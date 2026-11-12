<?php

namespace App\Filament\Admin\Widgets;

use App\Models\Transaction;
use Filament\Widgets\ChartWidget;
use Filament\Support\RawJs;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class ExpensesByCategoryChart extends ChartWidget
{
    protected static ?string $heading = 'Траты по категориям';
    protected static ?int $sort = 3;

    protected function getFilters(): ?array
    {
        return [
            'week' => 'За неделю',
            'month' => 'За месяц',
            'year' => 'За год',
        ];
    }

    protected function getData(): array
    {
        $walletId = session('active_wallet_id') ?? auth()->user()?->current_wallet_id;
        $activeFilter = $this->filter;

        $startDate = match ($activeFilter) {
            'week' => Carbon::today()->subDays(7),
            'year' => Carbon::today()->subYear(),
            default => Carbon::today()->subMonth(),
        };

        $transactions = Transaction::with('category')
            ->where('wallet_id', $walletId)
            ->where('created_at', '>=', $startDate)
            ->select('category_id', DB::raw('SUM(amount) as total'))
            ->groupBy('category_id')
            ->get();

        $labels = $transactions->map(fn ($t) => $t->category->name ?? 'Без категории')->toArray();
        $data = $transactions->map(fn ($t) => $t->total / 100)->toArray();

        return [
            'datasets' => [
                [
                    'label' => 'Сумма',
                    'data' => $data,
                    'backgroundColor' => [
                        '#ef4444', '#f97316', '#eab308', '#22c55e', '#3b82f6', '#8b5cf6', '#ec4899',
                    ],
                ],
            ],
            'labels' => $labels,
        ];
    }

    protected function getType(): string
    {
        return 'doughnut';
    }

    protected function getOptions(): array
    {
        return [
            'scales' => [
                'x' => ['display' => false],
                'y' => ['display' => false],
            ],
        ];
    }
}
