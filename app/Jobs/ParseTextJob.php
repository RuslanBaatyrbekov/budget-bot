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

        // Передаем нейросети текущую дату и время, чтобы она могла вычислять слова "вчера", "сегодня", "час назад"
        $currentDate = now()->format('Y-m-d H:i:s');

        $prompt = "Ты умный финансовый помощник. Пользователь написал текст о своих расходах: '{$this->text}'.
        Твоя задача — извлечь данные и вернуть ТОЛЬКО валидный JSON (без форматирования markdown и лишних слов).
        Текущая дата и время: {$currentDate}. Если пользователь указывает время (например, 'вчера', 'утром'), вычисли правильную дату на основе текущей. Если время не указано, используй текущую.
        Если способ оплаты не указан, ставь 'unknown'.

        Формат ответа строго такой:
        {
          \"store_name\": \"Название места/магазина (если есть, иначе null)\",
          \"date\": \"YYYY-MM-DD HH:MM:SS\",
          \"payment_method\": \"cash, card или unknown\",
          \"items\": [
            {
              \"amount\": 150.50,
              \"description\": \"Название товара или услуги\",
              \"category_name\": \"Подходящая категория на русском (например: Продукты, Транспорт, Кафе)\",
              \"category_icon\": \"Эмодзи категории\"
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

        // Очищаем от возможных артефактов markdown
        $data = str_replace(['```json', '```'], '', trim($data));
        $parsed = json_decode($data, true);

        if (!$parsed || !isset($parsed['items']) || empty($parsed['items'])) {
            $chat->html("❌ Не смог распознать траты в твоем сообщении. Напиши, пожалуйста, чуть конкретнее.")->send();
            return;
        }

        $total = 0;
        $itemsText = "";
        $wallet = Wallet::find($user->current_wallet_id);
        $currency = $wallet->currency ?? 'сом';

        // Вытаскиваем способ оплаты, если ИИ его не нашел — ставим unknown
        $paymentMethod = $parsed['payment_method'] ?? 'unknown';
        $transactionIds = []; // Сюда соберем ID созданных транзакций

        foreach ($parsed['items'] as $item) {
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
                'amount' => $item['amount'] * 100, // Переводим в копейки
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

        // Если ИИ не понял, как была произведена оплата — спрашиваем сами
        if ($paymentMethod === 'unknown') {
            $cacheKey = 'pay_' . uniqid();
            // Сохраняем ID транзакций в кэш на 2 часа, чтобы обновить их по клику
            \Illuminate\Support\Facades\Cache::put($cacheKey, $transactionIds, now()->addHours(2));

            $chat->html($messageText . "\n\n❓ <b>Уточни, пожалуйста, как ты это оплатил?</b>")
                ->keyboard(\DefStudio\Telegraph\Keyboard\Keyboard::make()->buttons([
                    \DefStudio\Telegraph\Keyboard\Button::make('💵 Наличными')->action('updatePayment')->param('method', 'cash')->param('key', $cacheKey),
                    \DefStudio\Telegraph\Keyboard\Button::make('💳 Картой')->action('updatePayment')->param('method', 'card')->param('key', $cacheKey),
                ]))->send();
        } else {
            // Если способ оплаты известен — просто отправляем отчет
            $chat->html($messageText)->send();
        }
    }

    public function failed(\Throwable $exception): void
    {
        $chat = TelegraphChat::find($this->chatId);
        $chat->html("❌ <b>Упс!</b>\nНе удалось разобрать текст из-за перегрузки AI серверов. Попробуй еще раз чуть позже.")->send();
    }
}
