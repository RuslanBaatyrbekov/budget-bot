<?php

namespace App\Jobs;

use App\Data\ParsedExpense;
use App\Enums\PaymentMethod;
use App\Exceptions\AiException;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Ai\ExpenseParser;
use App\Services\BudgetService;
use App\Services\TransactionRecorder;
use App\Telegram\Keyboards;
use App\Telegram\Messages;
use DefStudio\Telegraph\Models\TelegraphChat;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Common flow for text / receipt / voice: parse with AI → store atomically → reply.
 * Exceptions are not swallowed, so $tries, $backoff and failed() actually work.
 */
abstract class ParseExpenseJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 4;

    public int $timeout = 120;

    /** @var list<int> */
    public array $backoff = [15, 45, 90];

    public function __construct(
        public int $userId,
        public int $chatId,
    ) {}

    /** Returns null when the job already answered the user (e.g. nothing recognized). */
    abstract protected function parse(ExpenseParser $parser, Wallet $wallet, TelegraphChat $chat): ?ParsedExpense;

    abstract protected function source(): string;

    protected function emptyMessage(): string
    {
        return '🤔 Не нашёл сумму. Напиши подробнее, например: <code>кофе 2,50</code> или <code>REWE 42 картой</code>.';
    }

    /** Text shown above the result, e.g. the recognized voice message. */
    protected function heard(): ?string
    {
        return null;
    }

    /** @param Collection<int, Transaction> $transactions */
    protected function afterRecord(Collection $transactions): void {}

    public function handle(ExpenseParser $parser, TransactionRecorder $recorder, BudgetService $budgets): void
    {
        $user = User::find($this->userId);
        $chat = TelegraphChat::find($this->chatId);
        $wallet = $user?->currentWallet;

        if (! $user || ! $chat || ! $wallet) {
            return;
        }

        try {
            $expense = $this->parse($parser, $wallet, $chat);
        } catch (AiException $e) {
            if ($e->isRateLimit()) {
                Log::channel('ai')->warning('Gemini rate limit, retrying later', ['job' => static::class]);
                $this->release(60);

                return;
            }

            throw $e;
        }

        if ($expense === null) {
            return;
        }

        if ($expense->isEmpty()) {
            $chat->html($this->emptyMessage())->send();

            return;
        }

        $transactions = $recorder->record($user, $wallet, $expense, $this->source());
        $this->afterRecord($transactions);

        // Data is saved: a Telegram hiccup from here on must not trigger a retry (it would duplicate records).
        try {
            $chat->html(Messages::recorded($wallet, $expense, $transactions, $budgets->alertsFor($wallet, $transactions), $this->heard()))
                ->keyboard(Keyboards::afterRecord(
                    $transactions->first()->batch_id,
                    $expense->paymentMethod === PaymentMethod::Unknown && $expense->type->value === 'expense',
                ))
                ->send();
        } catch (Throwable $e) {
            report($e);
        }
    }

    public function failed(?Throwable $exception): void
    {
        $chat = TelegraphChat::find($this->chatId);

        $text = $exception instanceof AiException && $exception->isTimeout()
            ? 'Сервис распознавания сейчас перегружен ⏳'
            : 'Не получилось обработать сообщение 😔';

        $chat?->html("❌ {$text}\nПопробуй ещё раз чуть позже.")->send();
    }
}
