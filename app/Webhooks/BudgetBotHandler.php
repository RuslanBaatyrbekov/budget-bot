<?php

namespace App\Webhooks;

use App\Jobs\ParseReceiptJob;
use App\Jobs\ParseTextJob;
use App\Jobs\ParseVoiceJob;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use App\Services\BudgetService;
use App\Services\CsvExporter;
use App\Services\RecurringPaymentService;
use App\Services\ReportService;
use App\Services\WalletService;
use App\Support\Html;
use App\Support\Money;
use App\Telegram\Keyboards;
use App\Telegram\Messages;
use DefStudio\Telegraph\DTO\User as TelegramUser;
use DefStudio\Telegraph\Handlers\WebhookHandler;
use DefStudio\Telegraph\Keyboard\Button;
use DefStudio\Telegraph\Keyboard\Keyboard;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Support\Stringable;

/**
 * Commands are public methods (/report → report()), inline buttons call the short
 * public methods below (pay, del, rep...). Every update passes through guard() first.
 */
class BudgetBotHandler extends WebhookHandler
{
    private ?TelegramUser $from = null;

    private ?User $user = null;

    // ---------------------------------------------------------------- access

    protected function handleMessage(): void
    {
        if ($this->guard()) {
            parent::handleMessage();
        }
    }

    protected function handleCallbackQuery(): void
    {
        if ($this->guard()) {
            parent::handleCallbackQuery();
        }
    }

    private function guard(): bool
    {
        // Telegram re-delivers updates when we answer slowly: process each one once.
        $updateId = $this->request->input('update_id');
        if ($updateId !== null && ! Cache::add("tg-update:{$this->bot->id}:{$updateId}", true, now()->addDay())) {
            return false;
        }

        $this->from = $this->message?->from() ?? $this->callbackQuery?->from();

        if ($this->from === null || $this->from->isBot()) {
            return false;
        }

        if (! in_array((string) $this->from->id(), config('budget.allowed_users'), true)) {
            $this->rejectAccess();

            return false;
        }

        $this->user = User::with('currentWallet')->where('telegram_id', $this->from->id())->first();

        return true;
    }

    private function rejectAccess(): void
    {
        if ($this->callbackQuery !== null) {
            $this->reply('Нет доступа');

            return;
        }

        if ($this->message?->chat()?->type() !== 'private') {
            return;
        }

        $this->chat->html(
            "Привет! 👋 Это приватный бот для семейного бюджета.\n\n"
            ."Хочешь такой же? Проект бесплатный и открытый — его можно развернуть для своей семьи:\n"
            ."<a href=\"https://github.com/RuslanBaatyrbekov/budget-bot\">GitHub · AI Budget Bot</a> 🚀\n\n"
            .'Твой Telegram ID: <code>'.$this->from->id().'</code>'
        )->send();
    }

    private function wallet(): ?Wallet
    {
        $wallet = $this->user?->currentWallet;

        if ($wallet === null) {
            $this->chat->html('Сначала отправь /start 🙂')->send();
        }

        return $wallet;
    }

    private function isCallback(): bool
    {
        return $this->callbackQuery !== null;
    }

    /**
     * Transactions of a batch, but only if they belong to one of the user's wallets.
     *
     * @return Collection<int, Transaction>
     */
    private function ownBatch(string $batchId): Collection
    {
        if ($this->user === null || $batchId === '') {
            return collect();
        }

        return Transaction::where('batch_id', $batchId)
            ->whereIn('wallet_id', $this->user->wallets()->pluck('wallets.id'))
            ->get();
    }

    // ---------------------------------------------------------------- messages

    protected function handleChatMessage(Stringable $text): void
    {
        $wallet = $this->wallet();
        if ($wallet === null) {
            return;
        }

        $chatId = $this->chat->id;
        $document = $this->message?->document();
        $voice = $this->message?->voice();

        if ($this->message?->photos()->isNotEmpty()) {
            ParseReceiptJob::dispatch($this->user->id, $chatId, $this->message->photos()->last()->id());
            $this->chat->html('📸 Разбираю чек…')->send();

            return;
        }

        if ($document !== null && $this->isReceiptFile($document->mimeType())) {
            ParseReceiptJob::dispatch($this->user->id, $chatId, $document->id(), $document->mimeType());
            $this->chat->html('📄 Разбираю чек…')->send();

            return;
        }

        if ($voice !== null) {
            if ($voice->duration() > config('budget.max_voice_seconds')) {
                $this->chat->html('🎙 Слишком длинное голосовое, уложись в 3 минуты.')->send();

                return;
            }

            ParseVoiceJob::dispatch($this->user->id, $chatId, $voice->id());
            $this->chat->html('🎙 Слушаю…')->send();

            return;
        }

        if ($text->trim()->isNotEmpty()) {
            ParseTextJob::dispatch($this->user->id, $chatId, (string) $text->trim()->limit(1000));
            $this->chat->html('⏳ Записываю…')->send();

            return;
        }

        $this->chat->html('Я понимаю текст, фото чеков и голосовые. /help — подсказка.')->send();
    }

    private function isReceiptFile(?string $mime): bool
    {
        return in_array($mime, ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'], true);
    }

    // ---------------------------------------------------------------- commands

    public function start(): void
    {
        $this->user ??= User::fromTelegram($this->from->id(), $this->from->firstName() ?: 'Пользователь');
        $wallet = app(WalletService::class)->ensureWallet($this->user);

        $this->chat->html(
            'Привет, <b>'.Html::e($this->user->name).'</b>! 👋 Кошелёк: <b>'.Html::e($wallet->name)."</b>\n\n".Messages::help()
        )->send();
    }

    public function help(): void
    {
        $this->chat->html(Messages::help())->send();
    }

    public function report(): void
    {
        if ($wallet = $this->wallet()) {
            $this->sendMonthly('rep', $wallet, now());
        }
    }

    public function stores(): void
    {
        if ($wallet = $this->wallet()) {
            $this->sendMonthly('sto', $wallet, now());
        }
    }

    public function limits(): void
    {
        if ($wallet = $this->wallet()) {
            $this->chat->html(Messages::limits($wallet, app(BudgetService::class)->status($wallet)))->send();
        }
    }

    /** /limit Продукты 400 · /limit 2000 · /limit Продукты 0 */
    public function limit(string $parameter = ''): void
    {
        $wallet = $this->wallet();
        if ($wallet === null) {
            return;
        }

        if (! preg_match('/^(?:(.*\S)\s+)?(\d+(?:[.,]\d{1,2})?)$/u', trim($parameter), $m) || Money::parse($m[2]) === null) {
            $this->chat->html(Messages::limits($wallet, collect()))->send();

            return;
        }

        $amount = Money::parse($m[2]);
        $categoryName = trim($m[1]);
        $category = null;

        if ($categoryName !== '') {
            $category = $this->findCategory($wallet, $categoryName);

            if ($category === null) {
                $names = $wallet->categories()->where('type', 'expense')->pluck('name')->map(fn ($n) => Html::e($n))->implode(', ');
                $this->chat->html('Не нашёл категорию «'.Html::e($categoryName)."».\nЕсть: {$names}")->send();

                return;
            }
        }

        $budget = app(BudgetService::class)->setLimit($wallet, $category, $amount);
        $label = $category?->label() ?? '📊 Все расходы';

        $this->chat->html($budget
            ? '🎯 Лимит «'.Html::e($label).'»: <b>'.Money::format($amount, $wallet->currencySign()).'</b> в месяц'
            : '🗑 Лимит «'.Html::e($label).'» убран'
        )->send();
    }

    /** /regular — list · /regular 850 Аренда 1 — add (amount, description, day of month) */
    public function regular(string $parameter = ''): void
    {
        $wallet = $this->wallet();
        if ($wallet === null) {
            return;
        }

        $parameter = trim($parameter);

        if ($parameter !== '') {
            if (! preg_match('/^(\d+(?:[.,]\d{1,2})?)\s+(.+?)\s+(\d{1,2})$/u', $parameter, $m) || (int) $m[3] < 1 || (int) $m[3] > 31) {
                $this->chat->html("Формат: <code>/regular сумма описание день</code>\nНапример: <code>/regular 850 Аренда 1</code>")->send();

                return;
            }

            $payment = app(RecurringPaymentService::class)->add($wallet, $this->user, Money::parse($m[1]), $m[2], (int) $m[3]);

            $this->chat->html('🔁 Каждое <b>'.$payment->day_of_month.'-е</b> число буду записывать «'
                .Html::e($payment->description).'» — <b>'.Money::format($payment->amount, $wallet->currencySign()).'</b>')->send();

            return;
        }

        $payments = $wallet->recurringPayments()->where('is_active', true)->orderBy('day_of_month')->get();

        if ($payments->isEmpty()) {
            $this->chat->html("🔁 Регулярных платежей нет.\n\nДобавить: <code>/regular 850 Аренда 1</code>\n(сумма, описание, день месяца)")->send();

            return;
        }

        $text = "🔁 <b>Регулярные платежи</b>\n\n";
        $keyboard = Keyboard::make();

        foreach ($payments as $payment) {
            $text .= $payment->day_of_month.'-го · '.Html::e($payment->description).' — <b>'.Money::format($payment->amount, $wallet->currencySign())."</b>\n";
            $keyboard = $keyboard->row([Button::make('🗑 '.$payment->description)->action('regDel')->param('id', $payment->id)]);
        }

        $text .= "\nВ месяц: <b>".Money::format($payments->sum('amount'), $wallet->currencySign()).'</b>';

        $this->chat->html($text)->keyboard($keyboard)->send();
    }

    /** /export — current month, /export 2026-10 — given month */
    public function export(string $parameter = ''): void
    {
        $wallet = $this->wallet();
        if ($wallet === null) {
            return;
        }

        $month = preg_match('/^(\d{4})-(\d{1,2})$/', trim($parameter), $m)
            ? Carbon::create((int) $m[1], (int) $m[2], 1)
            : now();

        $path = app(CsvExporter::class)->month($wallet, $month);

        try {
            $this->chat->document($path, 'budget-'.$month->format('Y-m').'.csv')
                ->html('📤 Выгрузка за '.Messages::monthName($month))
                ->send();
        } finally {
            @unlink($path);
        }
    }

    public function undo(): void
    {
        $wallet = $this->wallet();
        if ($wallet === null) {
            return;
        }

        $last = $this->user->transactions()->where('wallet_id', $wallet->id)->latest('id')->first();

        if ($last === null) {
            $this->chat->html('Не нашёл твоих записей 🤷')->send();

            return;
        }

        $batch = $this->ownBatch($last->batch_id);
        $this->chat->html($this->deletePrompt($wallet, $batch))->keyboard(Keyboards::confirmDelete($last->batch_id))->send();
    }

    public function invite(): void
    {
        $wallet = $this->wallet();
        if ($wallet === null) {
            return;
        }

        $invite = app(WalletService::class)->createInvite($wallet, $this->user);

        $this->chat->html(
            '🔗 <b>Приглашение в «'.Html::e($wallet->name)."»</b>\n\n"
            ."Перешли это сообщение. Второму человеку нужно открыть бота и отправить:\n\n"
            ."<code>/join {$invite->code}</code>\n\n"
            .'Код одноразовый, действует '.config('budget.invite_ttl_hours').' ч.'
        )->send();
    }

    public function join(string $parameter = ''): void
    {
        if (trim($parameter) === '') {
            $this->chat->html('Отправь команду вместе с кодом: <code>/join КОД</code>')->send();

            return;
        }

        $this->user ??= User::fromTelegram($this->from->id(), $this->from->firstName() ?: 'Пользователь');
        $wallet = app(WalletService::class)->redeemInvite($parameter, $this->user);

        if ($wallet === null) {
            $this->chat->html('Код неверный, уже использован или истёк 😔 Попроси новый через /invite')->send();

            return;
        }

        $this->chat->html('🎉 Готово, <b>'.Html::e($this->user->name).'</b>! Теперь вы ведёте общий бюджет «'.Html::e($wallet->name).'».')->send();
    }

    public function currency(): void
    {
        if ($this->wallet()) {
            $this->chat->html('Выбери валюту кошелька:')->keyboard(Keyboards::currencies())->send();
        }
    }

    public function web(): void
    {
        if ($this->wallet() === null) {
            return;
        }

        $minutes = config('budget.web_login_ttl_minutes');
        $url = URL::temporarySignedRoute('login.telegram', now()->addMinutes($minutes), ['user' => $this->user->id, 'nonce' => Str::random(32)]);

        $this->chat->html("🔐 Вход в веб-панель (ссылка действует {$minutes} мин, никому не пересылай):")
            ->keyboard(Keyboard::make()->buttons([Button::make('Открыть панель')->url($url)]))
            ->send();
    }

    // ---------------------------------------------------------------- buttons

    public function pay(string $b = '', string $m = ''): void
    {
        $batch = $this->isCallback() ? $this->ownBatch($b) : collect();

        if ($batch->isEmpty() || ! in_array($m, ['cash', 'card'], true)) {
            $this->reply('Запись не найдена');

            return;
        }

        Transaction::whereKey($batch->modelKeys())->update(['payment_method' => $m]);

        $this->replaceKeyboard(Keyboards::afterRecord($b, false));
        $this->reply($m === 'cash' ? '💵 Наличными' : '💳 Картой');
    }

    public function del(string $b = ''): void
    {
        $batch = $this->isCallback() ? $this->ownBatch($b) : collect();

        if ($batch->isEmpty()) {
            $this->reply('Запись уже удалена');

            return;
        }

        $this->chat->html($this->deletePrompt($batch->first()->wallet, $batch))->keyboard(Keyboards::confirmDelete($b))->send();
        $this->reply('');
    }

    public function delOk(string $b = ''): void
    {
        $batch = $this->isCallback() ? $this->ownBatch($b) : collect();

        if ($batch->isEmpty()) {
            $this->chat->edit($this->messageId)->html('🤷 Записи не найдены (возможно, уже удалены).')->send();

            return;
        }

        Transaction::whereKey($batch->modelKeys())->delete();

        $this->chat->edit($this->messageId)->html($batch->count() > 1
            ? "🗑 <b>Удалено {$batch->count()} позиций</b>"
            : '🗑 <b>Запись удалена</b>')->send();
    }

    public function delNo(): void
    {
        if ($this->isCallback()) {
            $this->chat->edit($this->messageId)->html('🛡 Отменено, запись осталась.')->send();
        }
    }

    public function rep(int $y = 0, int $m = 0): void
    {
        if ($this->isCallback() && ($wallet = $this->wallet()) && $month = $this->validMonth($y, $m)) {
            $this->sendMonthly('rep', $wallet, $month, edit: true);
        }
    }

    public function sto(int $y = 0, int $m = 0): void
    {
        if ($this->isCallback() && ($wallet = $this->wallet()) && $month = $this->validMonth($y, $m)) {
            $this->sendMonthly('sto', $wallet, $month, edit: true);
        }
    }

    public function cur(string $c = ''): void
    {
        $wallet = $this->isCallback() ? $this->wallet() : null;

        if ($wallet === null || ! in_array($c, config('budget.currencies'), true)) {
            return;
        }

        $wallet->update(['currency' => $c]);
        $this->chat->edit($this->messageId)->html('✅ Валюта кошелька: <b>'.Html::e($c).'</b>')->send();
    }

    public function regDel(int $id = 0): void
    {
        $wallet = $this->isCallback() ? $this->wallet() : null;
        $payment = $wallet?->recurringPayments()->whereKey($id)->first();

        if ($payment === null) {
            $this->reply('Платёж не найден');

            return;
        }

        $payment->update(['is_active' => false]);
        $this->chat->edit($this->messageId)->html('🗑 Регулярный платёж «'.Html::e($payment->description).'» отключён.')->send();
    }

    // ---------------------------------------------------------------- helpers

    private function sendMonthly(string $action, Wallet $wallet, Carbon $month, bool $edit = false): void
    {
        $reports = app(ReportService::class);

        $text = $action === 'rep'
            ? Messages::monthReport($wallet, $month, $reports->month($wallet, $month))
            : Messages::storesReport($wallet, $month, $reports->stores($wallet, $month));

        $telegraph = $edit ? $this->chat->edit($this->messageId) : $this->chat;
        $telegraph->html($text)->keyboard(Keyboards::monthPager($action, $month))->send();
    }

    private function validMonth(int $year, int $month): ?Carbon
    {
        if ($year < 2000 || $month < 1 || $month > 12) {
            return null;
        }

        $date = Carbon::create($year, $month, 1);

        return $date->isAfter(now()) ? null : $date;
    }

    /** @param Collection<int, Transaction> $batch */
    private function deletePrompt(Wallet $wallet, Collection $batch): string
    {
        $first = $batch->first();
        $title = $batch->count() > 1
            ? 'весь чек'.($first->store ? ' «'.Html::e($first->store).'»' : '')." ({$batch->count()} поз.)"
            : '«'.Html::e($first->description).'»';

        return "⚠️ <b>Удалить {$title}?</b>\n💰 ".Money::format((int) $batch->sum('amount'), $wallet->currencySign());
    }

    private function findCategory(Wallet $wallet, string $name): ?Category
    {
        $needle = mb_strtolower($name);

        return $wallet->categories()->get()
            ->sortBy(fn (Category $c) => mb_strtolower($c->name) === $needle ? 0 : 1)
            ->first(fn (Category $c) => str_contains(mb_strtolower($c->name), $needle));
    }
}
