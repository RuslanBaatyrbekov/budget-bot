<?php

namespace App\Webhooks;

use App\Models\User;
use App\Models\Wallet;
use App\Models\Category;
use DefStudio\Telegraph\Handlers\WebhookHandler;
use DefStudio\Telegraph\Keyboard\Button;
use DefStudio\Telegraph\Keyboard\Keyboard;
use Illuminate\Support\Str;
use Illuminate\Support\Stringable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;


class BudgetBotHandler extends WebhookHandler
{
    public function start(): void
    {
        $telegramId =$this->message?->from()?->id();
        $firstName =$this->message?->from()?->firstName() ?? 'Пользователь';

        if (!$telegramId) {$this->chat->html("Упс, не смог получить твой Telegram ID.")->send();
            return;
        }

        $user = User::firstOrCreate(
            ['telegram_id' => $telegramId],
            [
                'name' => $firstName,
                'email' => $telegramId . '@telegram.bot',
                'password' => bcrypt(Str::random(16)),
            ]
        );

        if (!$user->current_wallet_id) {$wallet = Wallet::create(['name' => 'Семейный бюджет']);
            $user->wallets()->attach($wallet->id, ['role' => 'owner']);
            $user->current_wallet_id =$wallet->id;
            $user->save();

            $defaultCategories = [
                ['name' => 'Продукты', 'icon' => '🛒', 'type' => 'expense'],
                ['name' => 'Авто / W124', 'icon' => '🚗', 'type' => 'expense'],
                ['name' => 'Электроника / ESP32', 'icon' => '🔌', 'type' => 'expense'],
                ['name' => 'Фастфуд / Glovo', 'icon' => '🍔', 'type' => 'expense'],
                ['name' => 'Зарплата', 'icon' => '💵', 'type' => 'income'],
            ];

            foreach ($defaultCategories as$category) {
                $wallet->categories()->create($category);
            }
        }

        $this->chat->html("Привет, <b>{$firstName}</b>! 👋\n\nЯ настроил твой персональный кошелек. Теперь ты можешь отправить мне текст (например, <code>1500.50 глово</code>) или фото чека!")->send();
    }

    protected function handleMessage(): void
    {
        $text =$this->message?->text();

        if ($text !== null && str_starts_with($text, '/')) {
            parent::handleMessage();
            return;
        }

        $user = User::where('telegram_id',$this->message?->from()?->id())->first();

        if (!$user || !$user->current_wallet_id) {$this->chat->html("Сначала отправь /start, чтобы я настроил твой профиль!")->send();
            return;
        }

        if ($this->message?->photos()?->isNotEmpty()) {
            $this->processReceipt($user);
            return;
        }

        if ($text !== null) {$this->handleTextTransaction(new Stringable($text),$user);
            return;
        }

        $this->chat->html("Я пока понимаю только текст и фото чеков! 🧐")->send();
    }

    protected function handleTextTransaction(Stringable $text, User$user): void
    {
        if (preg_match('/^(\d+(?:[.,]\d+)?)\s+(.+)$/u', trim($text),$matches)) {
            $amount = (float) str_replace(',', '.',$matches[1]);
            $description = trim($matches[2]);

            $category = Category::where('wallet_id',$user->current_wallet_id)
                ->where('name', 'LIKE', "%{$description}%")
                ->first();

            $this->saveTransaction($user,$amount, $description,$category);
        } else {
            $this->chat->html("Не совсем понял формат. Напиши сумму и на что потратил, например:\n<code>450.50 глово</code>")->send();
        }
    }

    protected function processReceipt(User $user): void
    {
        $this->chat->html("Фото получено! 📸 Анализирую чек и определяю магазин...")->send();

        try {
            $photo = $this->message->photos()->last();
            $token = $this->bot->token;
            $telegramResponse = Http::get("https://api.telegram.org/bot{$token}/getFile?file_id={$photo->id()}");
            $filePath = $telegramResponse->json('result.file_path');
            $photoUrl = "https://api.telegram.org/file/bot{$token}/{$filePath}";

            $imageContents = Http::get($photoUrl)->body();
            $base64Image = base64_encode($imageContents);

            $categoriesList = Category::where('wallet_id', $user->current_wallet_id)->pluck('name')->implode(', ');

            $apiKey = env('GEMINI_API_KEY');
            $geminiResponse = Http::post("https://generativelanguage.googleapis.com/v1beta/models/gemini-3-flash-preview:generateContent?key={$apiKey}", [
                'contents' => [
                    [
                        'parts' => [
                            [
                                'text' => "Проанализируй этот чек. Верни ТОЛЬКО валидный JSON-объект с двумя ключами: \"store_name\" (строка, название магазина или заведения. Если неизвестно - \"Неизвестно\") и \"items\" (массив объектов). Каждый объект в массиве items — это товар с полями: \"amount\" (число с плавающей точкой), \"description\" (название товара), \"category_name\" (выбери из списка: [{$categoriesList}]. Если ничего не подходит, придумай короткое название для новой категории, максимум 2 слова), \"category_icon\" (строго один эмодзи). Игнорируй промежуточные итоги и налоги. Не используй маркдаун."
                            ],
                            [
                                'inlineData' => [
                                    'mimeType' => 'image/jpeg',
                                    'data' => $base64Image
                                ]
                            ]
                        ]
                    ]
                ]
            ]);

            if ($geminiResponse->failed()) {
                $this->chat->html("❌ Ошибка Gemini API: " . $geminiResponse->json('error.message'))->send();
                return;
            }

            $rawContent = $geminiResponse->json('candidates.0.content.parts.0.text');
            $cleanContent = str_replace(['```json', '```', "\n"], '', $rawContent);
            $aiData = json_decode(trim($cleanContent), true);

            if (!$aiData || !isset($aiData['items'])) {
                $this->chat->html("Не смог распарсить данные чека 😔\nСырой ответ: <code>{$rawContent}</code>")->send();
                return;
            }

            $storeName = $aiData['store_name'] ?? 'Неизвестный магазин';
            $items = $aiData['items'];
            $batchId = (string) \Illuminate\Support\Str::uuid();
            $totalAmount = 0;

            $text = "🏪 <b>{$storeName}</b>\n🧾 Чек обработан:\n\n";

            foreach ($items as $item) {
                if (!isset($item['amount'])) continue;

                $catName = $item['category_name'] ?? 'Разное';
                $catIcon = $item['category_icon'] ?? '📦';
                $amount = (float) $item['amount'];

                $category = Category::firstOrCreate(
                    ['wallet_id' => $user->current_wallet_id, 'name' => $catName],
                    ['icon' => $catIcon, 'type' => 'expense']
                );

                $totalAmount += $amount;

                $this->saveTransaction($user, $amount, $item['description'], $category, [
                    'source' => 'receipt_photo',
                    'batch_id' => $batchId,
                    'store' => $storeName
                ], true);

                $formattedAmount = number_format($amount, 2, '.', ' ');
                $text .= "{$category->icon} {$item['description']} — <b>{$formattedAmount}</b>\n";
            }

            $formattedTotal = number_format($totalAmount, 2, '.', ' ');
            $text .= "\nИтого сохранено: <b>{$formattedTotal}</b>";

            $this->chat->html($text)
                ->keyboard(\DefStudio\Telegraph\Keyboard\Keyboard::make()->buttons([
                    \DefStudio\Telegraph\Keyboard\Button::make('❌ Удалить весь чек')->action('confirmUndo')->param('batch_id', $batchId),
                ]))->send();

        } catch (\Exception $e) {
            $this->chat->html("Произошла ошибка: " . $e->getMessage())->send();
        }
    }

    protected function saveTransaction(User $user, float$amount, string $description, ?Category $category = null, array $aiMetadata = null, bool$silent = false): void
    {
        $transaction =$user->transactions()->create([
            'wallet_id' => $user->current_wallet_id,
            'category_id' => $category?->id,
            'amount' => (int) round($amount * 100),
            'type' => 'expense',
            'description' => $description,
            'ai_metadata' => $aiMetadata,
            'transaction_date' => now(),
        ]);

        if (!$silent) {
            $categoryName =$category ? $category->icon . ' ' .$category->name : '🤷 Без категории';
            $formattedAmount = number_format($amount, 2, '.', ' ');
            $this->chat->html("✅ Записал расход!\n\n💰 Сумма: <b>{$formattedAmount}</b>\n📝 Описание: <i>{$description}</i>\n📁 Категория: {$categoryName}")
                ->keyboard(\DefStudio\Telegraph\Keyboard\Keyboard::make()->buttons([
                    \DefStudio\Telegraph\Keyboard\Button::make('❌ Отменить')->action('confirmUndo')->param('id', $transaction->id),
                ]))->send();
        }
    }

    public function report(): void
    {
        $user = User::where('telegram_id', $this->message?->from()?->id())->first();

        if (!$user || !$user->current_wallet_id) {
            $this->chat->html("Сначала отправь /start, чтобы я настроил твой профиль!")->send();
            return;
        }

        $walletId = $user->current_wallet_id;
        $startOfMonth = now()->startOfMonth();

        $totals = \App\Models\Transaction::select('category_id', DB::raw('SUM(amount) as total'))
            ->where('wallet_id', $walletId)
            ->where('type', 'expense')
            ->where('transaction_date', '>=', $startOfMonth)
            ->groupBy('category_id')
            ->get();

        if ($totals->isEmpty()) {
            $this->chat->html("В этом месяце у тебя еще не было расходов! 💸")->send();
            return;
        }

        $totalSum = $totals->sum('total') / 100;

        $categoryIds = $totals->pluck('category_id')->filter();
        $categories = Category::whereIn('id', $categoryIds)->get()->keyBy('id');

        $text = "📊 <b>Отчет за месяц:</b>\n\n";

        foreach ($totals as $row) {
            $cat = $categories->get($row->category_id);
            $catName = $cat ? $cat->icon . ' ' . $cat->name : '🤷 Без категории';
            $sum = number_format($row->total / 100, 2, '.', ' ');
            $text .= "{$catName}: <b>{$sum}</b>\n";
        }

        $formattedTotal = number_format($totalSum, 2, '.', ' ');
        $text .= "\nИтого потрачено: <b>{$formattedTotal}</b>";

        $this->chat->html($text)->send();
    }

    public function stores(): void
    {
        $user = User::where('telegram_id', $this->message?->from()?->id())->first();

        if (!$user || !$user->current_wallet_id) {
            $this->chat->html("Сначала отправь /start!")->send();
            return;
        }

        $startOfMonth = now()->startOfMonth();

        $transactions = \App\Models\Transaction::where('wallet_id', $user->current_wallet_id)
            ->where('type', 'expense')
            ->where('transaction_date', '>=', $startOfMonth)
            ->get();

        if ($transactions->isEmpty()) {
            $this->chat->html("В этом месяце еще нет расходов! 💸")->send();
            return;
        }

        $storeTotals = [];
        $totalSum = 0;

        foreach ($transactions as $transaction) {
            $metadata = is_array($transaction->ai_metadata) ? $transaction->ai_metadata : json_decode($transaction->ai_metadata ?? '{}', true);

            $store = $metadata['store'] ?? 'Разное / Вручную';

            if (!isset($storeTotals[$store])) {
                $storeTotals[$store] = 0;
            }

            $storeTotals[$store] += $transaction->amount;
            $totalSum += $transaction->amount;
        }

        // Сортируем магазины от самых больших трат к самым маленьким
        arsort($storeTotals);

        $text = "🏪 <b>Траты по магазинам за месяц:</b>\n\n";

        foreach ($storeTotals as $store => $amount) {
            $sum = number_format($amount / 100, 2, '.', ' ');
            $icon = $store === 'Разное / Вручную' ? '✍️' : '🏬';
            $text .= "{$icon} {$store}: <b>{$sum}</b>\n";
        }

        $formattedTotal = number_format($totalSum / 100, 2, '.', ' ');
        $text .= "\nИтого: <b>{$formattedTotal}</b>";

        $this->chat->html($text)->send();
    }

    public function invite(): void
    {
        $user = User::where('telegram_id', $this->message?->from()?->id())->first();

        if (!$user || !$user->current_wallet_id) {
            $this->chat->html("Сначала отправь /start!")->send();
            return;
        }

        $token = base64_encode($user->current_wallet_id);

        $this->chat->html("🔗 <b>Приглашение в семью</b>\n\nПерешли это сообщение тому, с кем хочешь вести общий бюджет. \nВторому человеку нужно запустить этого же бота и отправить ему команду:\n\n<code>/join {$token}</code>")->send();
    }

    public function join(): void
    {
        $text = $this->message?->text();
        // Вырезаем команду из текста, чтобы получить сам токен
        $token = trim(str_replace('/join', '', $text));

        if (empty($token)) {
            $this->chat->html("Не вижу кода приглашения! Убедись, что скопировал команду целиком.")->send();
            return;
        }

        $walletId = base64_decode($token);
        $wallet = Wallet::find($walletId);

        if (!$wallet) {
            $this->chat->html("Кошелек не найден или код неверный 😔")->send();
            return;
        }

        $telegramId = $this->message?->from()?->id();
        $firstName = $this->message?->from()?->firstName() ?? 'Пользователь';

        $user = User::firstOrCreate(
            ['telegram_id' => $telegramId],
            [
                'name' => $firstName,
                'email' => $telegramId . '@telegram.bot',
                'password' => bcrypt(\Illuminate\Support\Str::random(16)),
            ]
        );

        if (!$user->wallets()->where('wallet_id', $wallet->id)->exists()) {
            $user->wallets()->attach($wallet->id, ['role' => 'member']);
        }

        $user->current_wallet_id = $wallet->id;
        $user->save();

        $this->chat->html("🎉 Ура, <b>{$firstName}</b>! Теперь ты подключен к кошельку <b>{$wallet->name}</b>. Все ваши траты теперь общие!")->send();
    }

    public function undo(): void
    {
        $user = User::where('telegram_id', $this->message?->from()?->id())->first();

        if (!$user || !$user->current_wallet_id) {
            $this->chat->html("Сначала отправь /start!")->send();
            return;
        }

        $lastTransaction = $user->transactions()
            ->where('wallet_id', $user->current_wallet_id)
            ->latest('id')
            ->first();

        if (!$lastTransaction) {
            $this->chat->html("Упс, не нашел ни одной твоей операции для отмены. 🤷‍♂️")->send();
            return;
        }

        $amount = number_format($lastTransaction->amount / 100, 2, '.', ' ');
        $description = $lastTransaction->description;

        $this->chat->html("⚠️ <b>Подтверди действие</b>\n\nТы точно хочешь удалить эту запись?\n💰 Сумма: <b>{$amount}</b>\n📝 Описание: <i>{$description}</i>")
            ->keyboard(Keyboard::make()->buttons([
                Button::make('✅ Да, удалить')->action('confirmUndo')->param('id', $lastTransaction->id),
                Button::make('❌ Отмена')->action('cancelUndo'),
            ]))->send();
    }

    public function confirmUndo(): void
    {
        $transactionId = $this->data->get('id');
        $batchId = $this->data->get('batch_id');

        if ($batchId) {
            $deletedCount = \App\Models\Transaction::where('ai_metadata->batch_id', $batchId)->delete();
            if ($deletedCount > 0) {
                $this->chat->edit($this->messageId)->html("🗑 <b>Весь чек ({$deletedCount} позиций) успешно удален!</b>")->send();
            } else {
                $this->chat->edit($this->messageId)->html("🤷‍♂️ Записи не найдены (возможно, уже удалены).")->send();
            }
        } elseif ($transactionId) {
            $transaction = \App\Models\Transaction::find($transactionId);
            if ($transaction) {
                $transaction->delete();
                $this->chat->edit($this->messageId)->html("🗑 <b>Операция успешно удалена!</b>")->send();
            } else {
                $this->chat->edit($this->messageId)->html("🤷‍♂️ Запись не найдена.")->send();
            }
        }
    }

    public function cancelUndo(): void
    {
        $this->chat->edit($this->messageId)->html("🛡 Действие отменено. Запись оставлена в бюджете.")->send();
    }
}
