<?php

namespace App\Filament\Admin\Widgets;

use App\Models\Transaction;
use App\Support\ActiveWallet;
use Filament\Widgets\ChartWidget;

class WeeklyRadarChart extends ChartWidget
{
    protected static ?string $heading = 'Траты по дням недели (последние 3 месяца)';

    protected static ?int $sort = 5;

    protected function getData(): array
    {
        // Aggregated in PHP: works the same on MySQL and SQLite (tests).
        $totals = Transaction::where('wallet_id', ActiveWallet::id())
            ->expenses()
            ->where('purchased_at', '>=', now()->subMonths(3)->startOfDay())
            ->get(['purchased_at', 'amount'])
            ->groupBy(fn (Transaction $t) => $t->purchased_at->dayOfWeekIso)
            ->map(fn ($group) => $group->sum('amount') / 100);

        return [
            'datasets' => [[
                'label' => 'Потрачено',
                'data' => collect(range(1, 7))->map(fn (int $day) => $totals->get($day, 0))->all(),
                'backgroundColor' => 'rgba(139, 92, 246, 0.2)',
                'borderColor' => '#8b5cf6',
                'pointBackgroundColor' => '#8b5cf6',
            ]],
            'labels' => ['Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб', 'Вс'],
        ];
    }

    protected function getType(): string
    {
        return 'radar';
    }
}
