<?php

namespace App\Services;

use App\Enums\TransactionType;
use App\Models\Transaction;
use App\Models\Wallet;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ReportService
{
    /**
     * @return array{
     *     expense: int, income: int, previous_expense: int,
     *     categories: Collection<int, object{name: string, icon: ?string, total: int}>,
     *     payments: Collection<string, int>,
     *     users: Collection<int, object{name: string, total: int}>,
     * }
     */
    public function month(Wallet $wallet, Carbon $month): array
    {
        $previous = $month->copy()->startOfMonth()->subMonth();

        return $this->period(
            $wallet,
            $month->copy()->startOfMonth(),
            $month->copy()->endOfMonth(),
            $previous,
            $previous->copy()->endOfMonth(),
        );
    }

    /** @return array<string, mixed> */
    public function period(Wallet $wallet, Carbon $from, Carbon $to, ?Carbon $previousFrom = null, ?Carbon $previousTo = null): array
    {
        $base = fn () => Transaction::query()
            ->where('transactions.wallet_id', $wallet->id)
            ->whereBetween('transactions.purchased_at', [$from, $to]);

        $previousTo ??= $from->copy()->subSecond();
        $previousFrom ??= $previousTo->copy()->subSeconds((int) $from->diffInSeconds($to));

        return [
            'from' => $from,
            'to' => $to,
            'expense' => (int) $base()->expenses()->sum('amount'),
            'income' => (int) $base()->incomes()->sum('amount'),
            'previous_expense' => (int) Transaction::where('wallet_id', $wallet->id)
                ->expenses()
                ->betweenDates($previousFrom, $previousTo)
                ->sum('amount'),
            'categories' => $base()->expenses()
                ->leftJoin('categories', 'categories.id', '=', 'transactions.category_id')
                ->groupBy('transactions.category_id', 'categories.name', 'categories.icon')
                ->orderByDesc('total')
                ->get([
                    DB::raw("COALESCE(categories.name, 'Без категории') as name"),
                    'categories.icon',
                    DB::raw('SUM(transactions.amount) as total'),
                ])
                ->map(fn ($row) => (object) ['name' => $row->name, 'icon' => $row->icon, 'total' => (int) $row->total]),
            'payments' => $base()->expenses()
                ->groupBy('payment_method')
                ->pluck(DB::raw('SUM(amount)'), 'payment_method')
                ->map(fn ($total) => (int) $total),
            'users' => $base()->expenses()
                ->join('users', 'users.id', '=', 'transactions.user_id')
                ->groupBy('transactions.user_id', 'users.name')
                ->orderByDesc('total')
                ->get(['users.name', DB::raw('SUM(transactions.amount) as total')])
                ->map(fn ($row) => (object) ['name' => $row->name, 'total' => (int) $row->total]),
        ];
    }

    /** @return Collection<int, object{store: string, total: int}> */
    public function stores(Wallet $wallet, Carbon $month): Collection
    {
        return Transaction::where('wallet_id', $wallet->id)
            ->expenses()
            ->forMonth($month)
            ->groupBy('store')
            ->orderByDesc('total')
            ->get(['store', DB::raw('SUM(amount) as total')])
            ->map(fn ($row) => (object) [
                'store' => $row->store ?: 'Без магазина',
                'total' => (int) $row->total,
            ]);
    }

    public function spentInMonth(Wallet $wallet, Carbon $month, ?int $categoryId = null): int
    {
        return (int) Transaction::where('wallet_id', $wallet->id)
            ->expenses()
            ->forMonth($month)
            ->when($categoryId, fn ($q) => $q->where('category_id', $categoryId))
            ->sum('amount');
    }

    public function typeLabel(TransactionType $type): string
    {
        return $type === TransactionType::Income ? 'доход' : 'расход';
    }
}
