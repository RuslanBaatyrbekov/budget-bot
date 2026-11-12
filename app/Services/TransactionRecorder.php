<?php

namespace App\Services;

use App\Data\ParsedExpense;
use App\Enums\TransactionType;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TransactionRecorder
{
    /**
     * Stores all items of a parsed message/receipt atomically.
     *
     * @return Collection<int, Transaction>
     */
    public function record(User $user, Wallet $wallet, ParsedExpense $expense, string $source): Collection
    {
        $batchId = (string) Str::uuid();
        $purchasedAt = $expense->date ?? now();

        return DB::transaction(function () use ($user, $wallet, $expense, $source, $batchId, $purchasedAt) {
            $transactions = collect();

            foreach ($expense->items as $item) {
                $category = $this->resolveCategory($wallet, $item->categoryName, $item->categoryIcon, $expense->type);

                $transactions->push(Transaction::create([
                    'wallet_id' => $wallet->id,
                    'user_id' => $user->id,
                    'category_id' => $category->id,
                    'amount' => $item->amount,
                    'type' => $expense->type,
                    'description' => $item->description,
                    'store' => $expense->store,
                    'batch_id' => $batchId,
                    'payment_method' => $expense->paymentMethod,
                    'ai_metadata' => ['source' => $source],
                    'transaction_date' => now(),
                    'purchased_at' => $purchasedAt,
                ])->setRelation('category', $category));
            }

            return $transactions;
        });
    }

    /** Finds a category ignoring case/extra spaces so "аптека" and "Аптека " do not split. */
    public function resolveCategory(Wallet $wallet, string $name, ?string $icon, TransactionType $type): Category
    {
        $normalized = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $name)));

        $existing = $wallet->categories()
            ->where('type', $type)
            ->get()
            ->first(fn (Category $category) => mb_strtolower(trim($category->name)) === $normalized);

        return $existing ?? $wallet->categories()->create([
            'name' => mb_convert_case(mb_substr($normalized, 0, 1), MB_CASE_UPPER).mb_substr($normalized, 1),
            'icon' => $icon ?: ($type === TransactionType::Income ? '💰' : '📦'),
            'type' => $type,
        ]);
    }
}
