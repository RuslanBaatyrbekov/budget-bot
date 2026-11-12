<?php

namespace App\Jobs;

use App\Models\User;
use App\Models\Category;
use App\Models\Transaction;
use DefStudio\Telegraph\Models\TelegraphChat;
use DefStudio\Telegraph\Keyboard\Button;
use DefStudio\Telegraph\Keyboard\Keyboard;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

class ParseReceiptJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 2;
    public $timeout = 120;

    public function __construct(
        public User $user,
        public string $photoId,
        public TelegraphChat $chat,
        public string $botToken
    ) {}

    public function handle(): void
    {
        try {
            $telegramResponse = Http::timeout(10)->get("https://api.telegram.org/bot{$this->botToken}/getFile?file_id={$this->photoId}");

            if ($telegramResponse->failed()) {
                throw new \Exception("Не удалось получить информацию о файле от Telegram.");
            }

            $filePath = $telegramResponse->json('result.file_path');
            $photoUrl = "https://api.telegram.org/file/bot{$this->botToken}/{$filePath}";
            $imageContents = Http::timeout(20)->get($photoUrl)->body();
            $base64Image = base64_encode($imageContents);

            $categoriesList = Category::where('wallet_id', $this->user->current_wallet_id)->pluck('name')->implode(', ');
            $apiKey = env('GEMINI_API_KEY');

            $prompt = "Проанализируй этот чек. Верни ТОЛЬКО валидный JSON-объект с 4 ключами:
            \"store_name\" (название магазина),
            \"date\" (дата YYYY-MM-DD HH:MM:SS или null),
            \"payment_method\" (строка: \"cash\" если наличные, \"card\" если безнал/карта/электронными, или \"unknown\" если не указано)
            и \"items\" (массив объектов).

            Каждый объект в items — это товар:
            \"amount\" (число),
            \"description\" (название),
            \"category_name\" (категория на русском. ВАЖНО: используй одну из существующих [{$categoriesList}] ТОЛЬКО если она идеально подходит по смыслу. Если товар туда не вписывается, ОБЯЗАТЕЛЬНО придумай новую точную категорию, например 'Бытовая химия', 'Аптека', 'Красота и уход', 'Одежда'),
            \"category_icon\" (один максимально подходящий эмодзи). Дополни все совими словами";







            $geminiResponse = Http::timeout(45)->post("https://generativelanguage.googleapis.com/v1beta/models/gemini-3.5-flash-lite:generateContent?key={$apiKey}", [
                'contents' => [[
                    'parts' => [
                        ['text' => $prompt],
                        ['inlineData' => ['mimeType' => 'image/jpeg', 'data' => $base64Image]]
                    ]
                ]]
            ]);

            if ($geminiResponse->failed()) {
                $statusCode = $geminiResponse->status();

                Log::channel('ai')->error("Gemini API Error (HTTP {$statusCode})", [
                    'response' => $geminiResponse->json()
                ]);

                if ($statusCode === 429) {
                    Log::channel('ai')->warning("Лимит запросов исчерпан. Откладываем задачу на 45 секунд...");
                    $this->release(45);
                    return;
                }

                throw new \Exception("Нейросеть ответила ошибкой. Код: " . $statusCode);
            }

            $rawContent = $geminiResponse->json('candidates.0.content.parts.0.text');

            Log::channel('ai')->info("Gemini Raw Response", [
                'user_id' => $this->user->id,
                'raw_text' => $rawContent
            ]);

            if (!$rawContent) {
                throw new \Exception("Пустой ответ от нейросети.");
            }

            $cleanContent = str_replace(['```json', '```', "\n"], '', $rawContent);
            $aiData = json_decode(trim($cleanContent), true);

            if (!$aiData || !isset($aiData['items'])) {
                Log::channel('ai')->error("JSON Parse Failed", [
                    'user_id' => $this->user->id,
                    'clean_text' => $cleanContent,
                    'json_error' => json_last_error_msg()
                ]);
                throw new \Exception("Не удалось расшифровать данные чека.");
            }

            $storeName = $aiData['store_name'] ?? 'Неизвестно';
            $receiptDate = $aiData['date'] ?? null;
            $paymentMethod = $aiData['payment_method'] ?? 'unknown';
            $items = $aiData['items'];
            $batchId = (string) Str::uuid();
            $totalAmount = 0;

            $methodText = match($paymentMethod) {
                'cash' => '💵 Наличными',
                'card' => '💳 Картой',
                default => '❓ Неизвестно'
            };

            $wallet = \App\Models\Wallet::find($this->user->current_wallet_id);
            $currency = $wallet->currency ?? '€';

            $text = "🏪 <b>{$storeName}</b>\n💳 Оплата: {$methodText}\n🧾 Чек:\n\n";

            foreach ($items as $item) {
                if (!isset($item['amount'])) continue;

                $catName = $item['category_name'] ?? 'Разное';
                $catIcon = $item['category_icon'] ?? '📦';
                $amount = (float) $item['amount'];

                $category = Category::firstOrCreate(
                    ['wallet_id' => $this->user->current_wallet_id, 'name' => $catName],
                    ['icon' => $catIcon, 'type' => 'expense']
                );

                $totalAmount += $amount;

                $this->user->transactions()->create([
                    'wallet_id' => $this->user->current_wallet_id,
                    'category_id' => $category->id,
                    'amount' => (int) round($amount * 100),
                    'type' => 'expense',
                    'description' => $item['description'],
                    'ai_metadata' => ['source' => 'receipt_photo', 'batch_id' => $batchId, 'store' => $storeName],
                    'payment_method' => $paymentMethod,
                    'transaction_date' => now(),
                    'purchased_at' => $receiptDate ? Carbon::parse($receiptDate) : now(),
                ]);

                $formattedAmount = number_format($amount, 2, '.', ' ') . " {$currency}";
                $text .= "{$category->icon} {$item['description']} — <b>{$formattedAmount}</b>\n";
            }

            $text .= "\nИтого: <b>" . number_format($totalAmount, 2, '.', ' ') . "</b>";

            $this->chat->html($text)->send();

            if ($paymentMethod === 'unknown') {
                $this->chat->html("Не смог определить способ оплаты по чеку. Как вы платили?")
                    ->keyboard(Keyboard::make()->buttons([
                        Button::make('💵 Наличными')->action('setPaymentMethod')->param('batch_id', $batchId)->param('method', 'cash'),
                        Button::make('💳 Картой')->action('setPaymentMethod')->param('batch_id', $batchId)->param('method', 'card'),
                    ]))->send();
            }

        } catch (Throwable $e) {
            report($e);

            Log::channel('ai')->error("Job Failed", [
                'message' => $e->getMessage(),
                'line' => $e->getLine(),
                'file' => $e->getFile(),
            ]);

            $errorMessage = str_contains($e->getMessage(), 'cURL error 28')
                ? "Серверы AI сейчас перегружены и не успели ответить. ⏳"
                : "Произошла ошибка при разборе чека. 😔";

            $this->chat->html("❌ <b>Упс!</b>\n{$errorMessage}\nПопробуйте загрузить фото чуть позже.")->send();
        }
    }

    /**
     * Вызывается, если задача окончательно провалилась после всех попыток.
     */
    public function failed(\Throwable $exception): void
    {
        $this->chat->html("❌ <b>Упс!</b>\nЯ честно пытался распознать чек несколько раз, но серверы AI перегружены лимитами. \n\nПожалуйста, отправь фото чека еще раз чуть позже. 😔")->send();
    }
}
