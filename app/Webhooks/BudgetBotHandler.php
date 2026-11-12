<?php

namespace App\Webhooks;

use App\Models\User;
use App\Models\Wallet;
use App\Models\Category;
use App\Models\Transaction;
use DefStudio\Telegraph\Handlers\WebhookHandler;
use DefStudio\Telegraph\Keyboard\Button;
use DefStudio\Telegraph\Keyboard\Keyboard;
use Illuminate\Support\Str;
use Illuminate\Support\Stringable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;

class BudgetBotHandler extends WebhookHandler
{
    /**
     * Проверяет, разрешено ли текущему пользователю использовать бота
     */
    private function isUserAllowed(): bool
    {
        $allowedString = env('ALLOWED_TELEGRAM_USERS', '');

        if (empty(trim($allowedString))) {
            return true;
        }

        $allowedUsers = array_filter(array_map('trim', explode(',', $allowedString)));
        $currentUserId = (string) ($this->message?->from()?->id() ?? $this->callbackQuery?->from()?->id());

        return in_array($currentUserId, $allowedUsers, true);
    }

    /**
     * Отправляет сообщение с отказом и ссылкой на GitHub
     */
    private function rejectAccess(): void
    {
        $this->chat->html(
            "Привет! 👋 Это приватный бот для ведения семейного бюджета.\n\n" .
            "Хочешь такого же? Проект полностью бесплатный и открытый! " .
            "Ты можешь в пару кликов развернуть его для своей семьи.\n\n" .
            "Исходный код и инструкция тут: <a href='https://github.com/RuslanBaatyrbekov/budget-bot'>GitHub - AI Budget Tracker</a> 🚀"
        )->send();
    }
    public function start(): void
    {
        if (!$this->isUserAllowed()) {
            $this->rejectAccess();
            return;
        }
        $telegramId = $this->message?->from()?->id();
        $firstName = $this->message?->from()?->firstName() ?? 'Пользователь';

        if (!$telegramId) {
            $this->chat->html("Упс, не смог получить твой Telegram ID.")->send();
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

        if (!$user->current_wallet_id) {
            $wallet = Wallet::create(['name' => 'Семейный бюджет']);
            $user->wallets()->attach($wallet->id, ['role' => 'owner']);
            $user->current_wallet_id = $wallet->id;
            $user->save();

            $defaultCategories = [
                ['name' => 'Продукты', 'icon' => '🛒', 'type' => 'expense'],
                ['name' => 'Авто / W124', 'icon' => '🚗', 'type' => 'expense'],
                ['name' => 'Электроника / ESP32', 'icon' => '🔌', 'type' => 'expense'],
                ['name' => 'Фастфуд / Glovo', 'icon' => '🍔', 'type' => 'expense'],
                ['name' => 'Зарплата', 'icon' => '💵', 'type' => 'income'],
            ];

            foreach ($defaultCategories as $category) {
                $wallet->categories()->create($category);
            }
        }

        $this->chat->html("Привет, <b>{$firstName}</b>! 👋\n\nОтправь мне текст (например, <code>3 евро за книгу</code>) или фото чека.")->send();
    }

    protected function handleMessage(): void
    {
        if (!$this->isUserAllowed()) {
            $this->rejectAccess();
            return;
        }

        $text = $this->message?->text();

        if ($text !== null && str_starts_with($text, '/')) {
            parent::handleMessage();
            return;
        }

        $user = User::where('telegram_id', $this->message?->from()?->id())->first();

        if (!$user || !$user->current_wallet_id) {
            $this->chat->html("Сначала отправь /start!")->send();
            return;
        }

        if ($this->message?->photos()?->isNotEmpty()) {
            $this->processReceipt($user);
            return;
        }

        if ($this->message?->voice() !== null) {
            $this->handleVoiceMessage($user, $this->message->voice()->id());
            return;
        }

        if ($text !== null) {
            $this->handleChatMessage(new Stringable($text));
            return;
        }

        $this->chat->html("Я пока понимаю только текст, фото чеков и голосовые сообщения!")->send();
    }


    protected function processReceipt(User $user): void
    {
        $photo = $this->message->photos()->last();
        $botToken = $this->bot->token;

        $this->chat->html("Производится анализ чека...")->send();

        \App\Jobs\ParseReceiptJob::dispatch($user, $photo->id(), $this->chat, $botToken);
    }

    protected function saveTransaction(User $user, float $amount, string $description, ?Category $category = null, array $aiMetadata = null, bool $silent = false, ?string $purchasedAt = null, string $paymentMethod = 'unknown'): Transaction
    {
        $transaction = $user->transactions()->create([
            'wallet_id' => $user->current_wallet_id,
            'category_id' => $category?->id,
            'amount' => (int) round($amount * 100),
            'type' => 'expense',
            'description' => $description,
            'ai_metadata' => $aiMetadata,
            'payment_method' => $paymentMethod,
            'transaction_date' => now(),
            'purchased_at' => $purchasedAt ? Carbon::parse($purchasedAt) : now(),
        ]);

        if (!$silent) {
            $wallet = Wallet::find($user->current_wallet_id);
            $currency = $wallet->currency ?? '€';
            $categoryName = $category ? $category->icon . ' ' . $category->name : '🤷 Без категории';
            $this->chat->html("✅ Записал расход!\n💰 Сумма: <b>" . number_format($amount, 2, '.', ' ') . " {$currency}</b>\n📝 <i>{$description}</i>\n📁 {$categoryName}")->send();
        }

        return $transaction;
    }

    public function setPaymentMethod(): void
    {
        if (!$this->isUserAllowed()) {
            $this->rejectAccess();
            return;
        }

        $method = $this->data->get('method');
        $batchId = $this->data->get('batch_id');
        $transactionId = $this->data->get('id');

        $methodName = $method === 'cash' ? '💵 Наличные' : '💳 Карта';

        if ($batchId) {
            Transaction::where('ai_metadata->batch_id', $batchId)->update(['payment_method' => $method]);
        } elseif ($transactionId) {
            Transaction::where('id', $transactionId)->update(['payment_method' => $method]);
        }

        $this->chat->edit($this->messageId)->html("✅ Способ оплаты сохранен: <b>{$methodName}</b>")->send();
    }

    public function report(): void
    {
        if (!$this->isUserAllowed()) {
            $this->rejectAccess();
            return;
        }

        $user = User::where('telegram_id', $this->message?->from()?->id())->first();

        if (!$user || !$user->current_wallet_id) {
            $this->chat->html("Сначала отправь /start, чтобы я настроил твой профиль!")->send();
            return;
        }

        // Запрашиваем отчет за текущий месяц по умолчанию
        $now = now();
        $payload = $this->generateReportPayload($user->current_wallet_id, $now->year, $now->month);

        $this->chat->html($payload['text'])
            ->keyboard($payload['keyboard'])
            ->send();
    }

    /**
     * Обработчик нажатия на кнопки "Вперед/Назад" по месяцам
     */
    public function changeReportMonth(): void
    {
        if (!$this->isUserAllowed()) {
            $this->rejectAccess();
            return;
        }

        $year = (int) $this->data->get('y');
        $month = (int) $this->data->get('m');

        $telegramId = $this->callbackQuery?->from()?->id() ?? $this->message?->from()?->id();
        $user = User::where('telegram_id', $telegramId)->first();

        if (!$user || !$user->current_wallet_id) {
            $this->chat->html("Пользователь не найден.")->send();
            return;
        }

        $payload = $this->generateReportPayload($user->current_wallet_id, $year, $month);

        $this->chat->edit($this->messageId)
            ->html($payload['text'])
            ->keyboard($payload['keyboard'])
            ->send();
    }

    /**
     * Вспомогательный метод: собирает текст отчета и клавиатуру для выбранного месяца
     */
    private function generateReportPayload(int $walletId, int $year, int $month): array
    {
        Carbon::setLocale('ru');

        $date = Carbon::create($year, $month, 1);
        $startOfMonth = $date->copy()->startOfMonth();
        $endOfMonth = $date->copy()->endOfMonth();

        $wallet = \App\Models\Wallet::find($walletId);
        $currency = $wallet->currency ?? '€';

        $totals = \App\Models\Transaction::select('category_id', DB::raw('SUM(amount) as total'))
            ->where('wallet_id', $walletId)
            ->where('type', 'expense')
            ->whereBetween('purchased_at', [$startOfMonth, $endOfMonth])
            ->groupBy('category_id')
            ->get();

        $monthName = mb_convert_case($date->translatedFormat('F Y'), MB_CASE_TITLE, 'UTF-8');
        $text = "📊 <b>Отчет за {$monthName}:</b>\n\n";

        if ($totals->isEmpty()) {
            $text .= "В этом месяце трат не найдено! 💸\n";
        } else {
            $totalSum = $totals->sum('total') / 100;
            $categoryIds = $totals->pluck('category_id')->filter();
            $categories = \App\Models\Category::whereIn('id', $categoryIds)->get()->keyBy('id');

            foreach ($totals as $row) {
                $cat = $categories->get($row->category_id);
                $catName = $cat ? $cat->icon . ' ' . $cat->name : '🤷 Без категории';
                $sum = number_format($row->total / 100, 2, '.', ' ') . " {$currency}";
                $text .= "{$catName}: <b>{$sum}</b>\n";
            }

            $paymentTotals = \App\Models\Transaction::select('payment_method', DB::raw('SUM(amount) as total'))
                ->where('wallet_id', $walletId)
                ->where('type', 'expense')
                ->whereBetween('purchased_at', [$startOfMonth, $endOfMonth])
                ->groupBy('payment_method')
                ->pluck('total', 'payment_method');

            $cashSum = ($paymentTotals->get('cash', 0)) / 100;
            $cardSum = ($paymentTotals->get('card', 0)) / 100;
            $unknownSum = ($paymentTotals->get('unknown', 0)) / 100;

            $text .= "\n💳 <b>Способы оплаты:</b>\n";
            if ($cashSum > 0) $text .= "💵 Наличными: <b>" . number_format($cashSum, 2, '.', ' ') . " {$currency}</b>\n";
            if ($cardSum > 0) $text .= "💳 Картой: <b>" . number_format($cardSum, 2, '.', ' ') . " {$currency}</b>\n";
            if ($unknownSum > 0) $text .= "❓ Неизвестно: <b>" . number_format($unknownSum, 2, '.', ' ') . " {$currency}</b>\n";

            $formattedTotal = number_format($totalSum, 2, '.', ' ') . " {$currency}";
            $text .= "\nИтого потрачено: <b>{$formattedTotal}</b>";
        }

        $prev = $date->copy()->subMonth();
        $next = $date->copy()->addMonth();
        $now = now();

        $buttons = [];

        $buttons[] = Button::make('⬅️ ' . mb_convert_case($prev->translatedFormat('M'), MB_CASE_TITLE, 'UTF-8'))
            ->action('changeReportMonth')
            ->param('y', $prev->year)
            ->param('m', $prev->month);

        if ($date->format('Y-m') !== $now->format('Y-m')) {
            $buttons[] = Button::make(mb_convert_case($next->translatedFormat('M'), MB_CASE_TITLE, 'UTF-8') . ' ➡️')
                ->action('changeReportMonth')
                ->param('y', $next->year)
                ->param('m', $next->month);
        }

        $keyboard = Keyboard::make()->row($buttons);

        return [
            'text' => $text,
            'keyboard' => $keyboard
        ];
    }
    public function stores(): void
    {
        if (!$this->isUserAllowed()) {
            $this->rejectAccess();
            return;
        }

        $user = User::where('telegram_id', $this->message?->from()?->id())->first();

        if (!$user || !$user->current_wallet_id) {
            $this->chat->html("Сначала отправь /start!")->send();
            return;
        }

        // Запрашиваем отчет по магазинам за текущий месяц по умолчанию
        $now = now();
        $payload = $this->generateStoresPayload($user->current_wallet_id, $now->year, $now->month);

        $this->chat->html($payload['text'])
            ->keyboard($payload['keyboard'])
            ->send();
    }

    /**
     * Обработчик нажатия на кнопки "Вперед/Назад" по месяцам для МАГАЗИНОВ
     */
    public function changeStoresMonth(): void
    {
        if (!$this->isUserAllowed()) {
            $this->rejectAccess();
            return;
        }

        $year = (int) $this->data->get('y');
        $month = (int) $this->data->get('m');

        $telegramId = $this->callbackQuery?->from()?->id() ?? $this->message?->from()?->id();
        $user = User::where('telegram_id', $telegramId)->first();

        if (!$user || !$user->current_wallet_id) {
            $this->chat->html("Пользователь не найден.")->send();
            return;
        }

        $payload = $this->generateStoresPayload($user->current_wallet_id, $year, $month);

        $this->chat->edit($this->messageId)
            ->html($payload['text'])
            ->keyboard($payload['keyboard'])
            ->send();
    }

    /**
     * Вспомогательный метод: собирает текст и клавиатуру для выбранного месяца (МАГАЗИНЫ)
     */
    private function generateStoresPayload(int $walletId, int $year, int $month): array
    {
        Carbon::setLocale('ru');

        $date = Carbon::create($year, $month, 1);
        $startOfMonth = $date->copy()->startOfMonth();
        $endOfMonth = $date->copy()->endOfMonth();

        $wallet = \App\Models\Wallet::find($walletId);
        $currency = $wallet->currency ?? '€';

        // Берем транзакции строго за выбранный месяц
        $transactions = \App\Models\Transaction::where('wallet_id', $walletId)
            ->where('type', 'expense')
            ->whereBetween('purchased_at', [$startOfMonth, $endOfMonth])
            ->get();

        $monthName = mb_convert_case($date->translatedFormat('F Y'), MB_CASE_TITLE, 'UTF-8');
        $text = "🏪 <b>Траты по магазинам за {$monthName}:</b>\n\n";

        if ($transactions->isEmpty()) {
            $text .= "В этом месяце еще нет расходов! 💸\n";
        } else {
            $storeTotals = [];
            $totalSum = 0;

            foreach ($transactions as $transaction) {
                $metadata = is_array($transaction->ai_metadata) ? $transaction->ai_metadata : json_decode($transaction->ai_metadata ?? '{}', true);

                // Учитываем оба варианта ключа (на всякий случай)
                $store = $metadata['store'] ?? $metadata['store_name'] ?? 'Разное / Вручную';

                if (!isset($storeTotals[$store])) {
                    $storeTotals[$store] = 0;
                }

                $storeTotals[$store] += $transaction->amount;
                $totalSum += $transaction->amount;
            }

            arsort($storeTotals);

            foreach ($storeTotals as $store => $amount) {
                $sum = number_format($amount / 100, 2, '.', ' ') . " {$currency}";
                $icon = $store === 'Разное / Вручную' ? '✍️' : '🏬';
                $text .= "{$icon} {$store}: <b>{$sum}</b>\n";
            }

            $formattedTotal = number_format($totalSum / 100, 2, '.', ' ') . " {$currency}";
            $text .= "\nИтого: <b>{$formattedTotal}</b>";
        }

        $prev = $date->copy()->subMonth();
        $next = $date->copy()->addMonth();
        $now = now();

        $buttons = [];

        $buttons[] = Button::make('⬅️ ' . mb_convert_case($prev->translatedFormat('M'), MB_CASE_TITLE, 'UTF-8'))
            ->action('changeStoresMonth')
            ->param('y', $prev->year)
            ->param('m', $prev->month);

        if ($date->format('Y-m') !== $now->format('Y-m')) {
            $buttons[] = Button::make(mb_convert_case($next->translatedFormat('M'), MB_CASE_TITLE, 'UTF-8') . ' ➡️')
                ->action('changeStoresMonth')
                ->param('y', $next->year)
                ->param('m', $next->month);
        }

        $keyboard = Keyboard::make()->row($buttons);

        return [
            'text' => $text,
            'keyboard' => $keyboard
        ];
    }

    public function invite(): void
    {
        if (!$this->isUserAllowed()) {
            $this->rejectAccess();
            return;
        }

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

        if (!$this->isUserAllowed()) {
            $this->rejectAccess();
            return;
        }
        $text = $this->message?->text();
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
                'password' => bcrypt(Str::random(16)),
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
        if (!$this->isUserAllowed()) {
            $this->rejectAccess();
            return;
        }

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
        if (!$this->isUserAllowed()) {
            $this->rejectAccess();
            return;
        }

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
        if (!$this->isUserAllowed()) {
            $this->rejectAccess();
            return;
        }

        $this->chat->edit($this->messageId)->html("🛡 Действие отменено. Запись оставлена в бюджете.")->send();
    }

    public function currency(): void
    {
        if (!$this->isUserAllowed()) {
            $this->rejectAccess();
            return;
        }

        $user = User::where('telegram_id', $this->message?->from()?->id())->first();

        if (!$user || !$user->current_wallet_id) {
            $this->chat->html("Сначала отправь /start!")->send();
            return;
        }

        $this->chat->html("Выберите валюту для вашего кошелька:")
            ->keyboard(Keyboard::make()->buttons([
                Button::make('€ Евро')->action('setCurrency')->param('c', '€'),
                Button::make('$ Доллар')->action('setCurrency')->param('c', '$'),
                Button::make('₽ Рубль')->action('setCurrency')->param('c', '₽'),
                Button::make('сом Сом')->action('setCurrency')->param('c', 'сом'),
            ]))->send();
    }

    public function setCurrency(): void
    {
        if (!$this->isUserAllowed()) {
            $this->rejectAccess();
            return;
        }

        $currencySign = $this->data->get('c');
        $user = User::where('telegram_id', $this->chat->chat_id)->first();

        if ($user && $user->current_wallet_id) {
            Wallet::where('id', $user->current_wallet_id)->update(['currency' => $currencySign]);
            $this->chat->edit($this->messageId)->html("✅ Валюта кошелька успешно изменена на <b>{$currencySign}</b>")->send();
        }
    }

    /**
     * Обработка обычных текстовых сообщений
     */
    protected function handleChatMessage(\Illuminate\Support\Stringable $text): void
    {
        $user = \App\Models\User::where('telegram_id', $this->message?->from()?->id())->first();

        if (!$user || !$user->current_wallet_id) {
            $this->chat->html("Сначала отправь /start, чтобы я настроил твой профиль!")->send();
            return;
        }

        $this->chat->html("Анализируем... ")->send();

        \App\Jobs\ParseTextJob::dispatch($user->id, $this->chat->id, $text->value());
    }

    protected function handleVoiceMessage(User $user, string $fileId): void
    {
        $token = $this->bot->token;

        $fileInfoResponse = Http::get("https://api.telegram.org/bot{$token}/getFile", [
            'file_id' => $fileId
        ]);

        if (! $fileInfoResponse->successful()) {
            $this->chat->html('Не удалось загрузить голосовое сообщение.')->send();
            return;
        }

        $filePath = $fileInfoResponse->json('result.file_path');

        $downloadUrl = "https://api.telegram.org/file/bot{$token}/{$filePath}";
        $fileContent = Http::get($downloadUrl)->body();

        $fileName = 'voice_' . Str::random(10) . '.ogg';
        $localPath = "temp/{$fileName}";
        Storage::disk('local')->put($localPath, $fileContent);

        $absolutePath = Storage::disk('local')->path($localPath);

        \App\Jobs\ParseVoiceJob::dispatch($user->id, (string)$this->chat->id, $absolutePath);

        $this->chat->html('Голосовое сообщение принято! Расшифровываем...')->send();
    }

    /**
     * Обработчик нажатия на кнопки выбора способа оплаты
     */
    public function updatePayment(): void
    {
        if (!$this->isUserAllowed()) {
            $this->rejectAccess();
            return;
        }

        $method = $this->data->get('method');
        $cacheKey = $this->data->get('key');
        $ids = \Illuminate\Support\Facades\Cache::get($cacheKey);

        if (!$ids) {
            $this->chat->html("❌ Время ожидания истекло или способ оплаты уже был выбран.")->send();
            $this->deleteKeyboard();
            return;
        }

        \App\Models\Transaction::whereIn('id', $ids)->update(['payment_method' => $method]);
        \Illuminate\Support\Facades\Cache::forget($cacheKey);
        $methodName = $method === 'cash' ? '💵 Наличными' : '💳 Картой';
        $this->deleteKeyboard();
        $this->chat->html("✅ Отлично! Способ оплаты обновлен на: <b>{$methodName}</b>")->send();
    }
}
