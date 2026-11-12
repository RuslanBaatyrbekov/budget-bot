<?php

namespace App\Services;

use App\Models\Budget;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\Wallet;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class BudgetService
{
    public function __construct(private readonly ReportService $reports) {}

    public function setLimit(Wallet $wallet, ?Category $category, int $amount): ?Budget
    {
        $query = $wallet->budgets()->where('category_id', $category?->id);

        if ($amount <= 0) {
            $query->delete();

            return null;
        }

        $budget = $query->first() ?? new Budget(['wallet_id' => $wallet->id, 'category_id' => $category?->id]);
        $budget->amount = $amount;
        $budget->save();

        return $budget;
    }

    /**
     * @return Collection<int, object{budget: Budget, spent: int, ratio: float}>
     */
    public function status(Wallet $wallet, ?Carbon $month = null): Collection
    {
        $month ??= now();

        return $wallet->budgets()->with('category')->get()
            ->map(function (Budget $budget) use ($wallet, $month) {
                $spent = $this->reports->spentInMonth($wallet, $month, $budget->category_id);

                return (object) [
                    'budget' => $budget,
                    'spent' => $spent,
                    'ratio' => $budget->amount > 0 ? $spent / $budget->amount : 0.0,
                ];
            })
            ->sortBy(fn ($row) => $row->budget->category_id === null ? 0 : 1)
            ->values();
    }

    /**
     * Budgets that just crossed a threshold because of these transactions.
     * Each threshold is reported once per month.
     *
     * @param  Collection<int, Transaction>  $transactions
     * @return Collection<int, object{budget: Budget, spent: int, ratio: float, threshold: float}>
     */
    public function alertsFor(Wallet $wallet, Collection $transactions): Collection
    {
        $categoryIds = $transactions->pluck('category_id')->filter()->unique();
        $month = now();

        return $this->status($wallet, $month)
            ->filter(fn ($row) => $row->budget->category_id === null || $categoryIds->contains($row->budget->category_id))
            ->map(function ($row) use ($month) {
                $crossed = collect(config('budget.limit_thresholds'))
                    ->sortDesc()
                    ->first(fn (float $threshold) => $row->ratio >= $threshold);

                if ($crossed === null) {
                    return null;
                }

                $key = sprintf('budget-alert:%d:%s:%s', $row->budget->id, $month->format('Y-m'), $crossed);

                if (! Cache::add($key, true, $month->copy()->endOfMonth()->addDay())) {
                    return null;
                }

                $row->threshold = $crossed;

                return $row;
            })
            ->filter()
            ->values();
    }
}
