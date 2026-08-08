<?php

namespace App\Jobs;

use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use DefStudio\Telegraph\Models\TelegraphChat;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ParseTextJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 5;

    public function __construct(
        public int $userId,
        public int $chatId,
        public string $text
    ) {}

    public function handle(): void
    {
        $user = User::find($this->userId);
        $chat = TelegraphChat::find($this->chatId);
        $apiKey = env('GEMINI_API_KEY');

        $currentDate = now()->format('Y-m-d H:i:s');
        $wallet = Wallet::find($user->current_wallet_id);
        $categoriesList = $wallet->categories()->pluck('name')->implode(', ');

        $prompt = "Ты умный финансовый помощник. Пользователь написал текст: '{$this->text}'.
        ВНИМАНИЕ: Если в тексте нет явного упоминания трат, покупок или нет никаких чисел/сумм, верни пустой массив items: [].
        В противном случае извлеки данные и верни ТОЛЬКО валидный JSON (без markdown).
        Текущая дата и время: {$currentDate}. Если пользователь указывает время ('вчера'), вычисли правильную дату.
        Если способ оплаты не указан, ставь 'unknown'.

        Формат ответа строго такой:
        {
          \"store_name\": \"Название (если есть, иначе null)\",
          \"date\": \"YYYY-MM-DD HH:MM:SS\",
          \"payment_method\": \"cash, card или unknown\",
          \"items\": [
            {
              \"amount\": 150.50,
              \"description\": \"Название\",
              \"category_name\": \"Категория на русском. ВАЖНО: используй одну из существующих [{$categoriesList}] ТОЛЬКО если она идеально подходит по смыслу. Если не подходит, ОБЯЗАТЕЛЬНО придумай новую точную категорию (например 'Транспорт', 'Аптека', 'Одежда')\",
              \"category_icon\": \"Максимально подходящий эмодзи\"
            }
          ]
        }";

        $geminiResponse = Http::timeout(45)->post("https://generativelanguage.googleapis.com/v1beta/models/gemini-3.5-flash-lite:generateContent?key={$apiKey}", [
            'contents' => [
                ['role' => 'user', 'parts' => [['text' => $prompt]]]
            ],
            'generationConfig' => [
                'responseMimeType' => 'application/json',
            ]
        ]);

        if ($geminiResponse->failed()) {
            $statusCode = $geminiResponse->status();

            if ($statusCode === 429) {
                Log::channel('ai')->warning("Лимит запросов исчерпан (Текст). Откладываем задачу на 45 секунд...");
                $this->release(45);
                return;
            }

            Log::channel('ai')->error("Gemini API Error (Text) HTTP {$statusCode}", ['response' => $geminiResponse->json()]);
            throw new \Exception("Ошибка API при обработке текста: " . $statusCode);
        }

        $data = $geminiResponse->json('candidates.0.content.parts.0.text');
        $data = str_replace(['```json', '```'], '', trim($data));
        $parsed = json_decode($data, true);

        $validItems = array_filter($parsed['items'] ?? [], function($item) {
            return isset($item['amount']) && floatval($item['amount']) > 0;
        });

        if (empty($validItems)) {
            $chat->html("В сообщении не указана сумма. Необходимо указать подробнее, например: <i>'ремонт 500'</i> или <i>'кофе 2.50'</i>.")->send();
            return;
        }

        $total = 0;
        $itemsText = "";
        $currency = $wallet->currency ?? 'сом';

        $paymentMethod = $parsed['payment_method'] ?? 'unknown';
        $transactionIds = [];

        foreach ($validItems as $item) {
            $category = Category::firstOrCreate(
                [
                    'name' => $item['category_name'],
                    'wallet_id' => $wallet->id
                ],
                [
                    'icon' => $item['category_icon'],
                    'type' => 'expense'
                ]
            );

            $transaction = Transaction::create([
                'user_id' => $user->id,
                'wallet_id' => $wallet->id,
                'category_id' => $category->id,
                'type' => 'expense',
                'amount' => $item['amount'] * 100,
                'description' => $item['description'],
                'payment_method' => $paymentMethod,
                'purchased_at' => $parsed['date'] ?? now(),
                'transaction_date' => now(),
            ]);

            $transactionIds[] = $transaction->id;
            $total += $item['amount'];
            $itemsText .= "{$category->icon} {$item['description']} — <b>" . number_format($item['amount'], 2, '.', ' ') . " {$currency}</b>\n";
        }

        $storeText = !empty($parsed['store_name']) ? "в <b>{$parsed['store_name']}</b> " : "";
        $messageText = "✅ Записал траты {$storeText}📝\n\n{$itemsText}\nИтого: <b>" . number_format($total, 2, '.', ' ') . " {$currency}</b>";

        if ($paymentMethod === 'unknown') {
            $cacheKey = 'pay_' . uniqid();
            \Illuminate\Support\Facades\Cache::put($cacheKey, $transactionIds, now()->addHours(2));

            $chat->html($messageText . "\n\n❓ <b>Уточните каким способом была произведена оплата?</b>")
                ->keyboard(\DefStudio\Telegraph\Keyboard\Keyboard::make()->buttons([
                    \DefStudio\Telegraph\Keyboard\Button::make('💵 Наличными')->action('updatePayment')->param('method', 'cash')->param('key', $cacheKey),
                    \DefStudio\Telegraph\Keyboard\Button::make('💳 Картой')->action('updatePayment')->param('method', 'card')->param('key', $cacheKey),
                ]))->send();
        } else {
            $chat->html($messageText)->send();
        }
    }

    public function failed(\Throwable $exception): void
    {
        $chat = TelegraphChat::find($this->chatId);
        $chat->html("❌ <b>Упс!</b>\nНе удалось разобрать текст из-за перегрузки AI серверов. Попробуй еще раз чуть позже.")->send();
    }
}
