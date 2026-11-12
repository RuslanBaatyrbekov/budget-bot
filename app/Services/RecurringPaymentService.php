<?php

namespace App\Services;

use App\Enums\TransactionType;
use App\Models\RecurringPayment;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Ai\ExpenseParser;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class RecurringPaymentService
{
    public function __construct(
        private readonly ExpenseParser $parser,
        private readonly TransactionRecorder $recorder,
    ) {}

    public function add(Wallet $wallet, User $user, int $amount, string $description, int $day): RecurringPayment
    {
        $categoryId = null;
        $type = TransactionType::Expense;

        // Let the AI pick the category; the payment is still saved if it is unavailable.
        try {
            $parsed = $this->parser->parseText($description.' '.($amount / 100), $wallet);
            $item = $parsed->items[0] ?? null;

            if ($item) {
                $type = $parsed->type;
                $categoryId = $this->recorder->resolveCategory($wallet, $item->categoryName, $item->categoryIcon, $type)->id;
            }
        } catch (Throwable $e) {
            report($e);
        }

        return $wallet->recurringPayments()->create([
            'user_id' => $user->id,
            'category_id' => $categoryId,
            'type' => $type,
            'amount' => $amount,
            'description' => Str::limit($description, 120, ''),
            'day_of_month' => max(1, min(31, $day)),
            'payment_method' => 'card',
            // Do not book the current month retroactively if the day has already passed.
            'last_run_on' => $day <= now()->day ? now()->toDateString() : null,
        ]);
    }

    /**
     * Books every payment that is due today or was missed earlier this month.
     *
     * @return Collection<int, Collection<int, Transaction>> transactions grouped by wallet id
     */
    public function runDue(?Carbon $today = null): Collection
    {
        $today ??= now();
        $created = collect();

        RecurringPayment::where('is_active', true)->with('category')->each(function (RecurringPayment $payment) use ($today, $created) {
            if (! $payment->isDueOn($today)) {
                return;
            }

            DB::transaction(function () use ($payment, $today, $created) {
                $transaction = Transaction::create([
                    'wallet_id' => $payment->wallet_id,
                    'user_id' => $payment->user_id,
                    'category_id' => $payment->category_id,
                    'amount' => $payment->amount,
                    'type' => $payment->type,
                    'description' => $payment->description,
                    'batch_id' => (string) Str::uuid(),
                    'payment_method' => $payment->payment_method,
                    'ai_metadata' => ['source' => 'recurring', 'recurring_id' => $payment->id],
                    'transaction_date' => now(),
                    'purchased_at' => $payment->dueDateIn($today)->setTime(9, 0),
                ])->setRelation('category', $payment->category);

                $payment->update(['last_run_on' => $today->toDateString()]);

                $created->push($transaction);
            });
        });

        return $created->groupBy('wallet_id');
    }
}
