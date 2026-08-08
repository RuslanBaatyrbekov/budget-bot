<?php

namespace App\Filament\Admin\Widgets;

use App\Models\Transaction;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class WeeklyRadarChart extends ChartWidget
{
    protected static ?string $heading = 'Траты по дням недели';
    protected static ?int $sort = 4;
    protected function getData(): array
    {
        $startOfMonth = Carbon::now()->startOfMonth();
        $endOfMonth = Carbon::now()->endOfMonth();
        $transactions = Transaction::select(
            DB::raw('DAYOFWEEK(purchased_at) as day_of_week'),
            DB::raw('SUM(amount) as total')
        )
            ->where('type', 'expense')
            ->whereBetween('purchased_at', [$startOfMonth, $endOfMonth])
            ->groupBy('day_of_week')
            ->pluck('total', 'day_of_week');

        $data = [
            ($transactions->get(2, 0)) / 100,
            ($transactions->get(3, 0)) / 100,
            ($transactions->get(4, 0)) / 100,
            ($transactions->get(5, 0)) / 100,
            ($transactions->get(6, 0)) / 100,
            ($transactions->get(7, 0)) / 100,
            ($transactions->get(1, 0)) / 100,
        ];

        return [
            'datasets' => [
                [
                    'label' => 'Потрачено (€)',
                    'data' => $data,
                    'backgroundColor' => 'rgba(139, 92, 246, 0.2)',
                    'borderColor' => '#8b5cf6',
                    'pointBackgroundColor' => '#8b5cf6',
                    'pointBorderColor' => '#fff',
                    'pointHoverBackgroundColor' => '#fff',
                    'pointHoverBorderColor' => '#8b5cf6',
                ],
            ],
            'labels' => ['Понедельник', 'Вторник', 'Среда', 'Четверг', 'Пятница', 'Суббота', 'Воскресенье'],
        ];
    }

    protected function getType(): string
    {
        return 'radar';
    }
}
