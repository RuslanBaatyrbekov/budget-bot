<?php

namespace App\Filament\Admin\Widgets;

use App\Services\ReportService;
use App\Support\ActiveWallet;
use Filament\Widgets\ChartWidget;

class StoreExpensesChart extends ChartWidget
{
    protected static ?string $heading = 'Траты по магазинам (этот месяц)';

    protected static ?int $sort = 4;

    protected function getData(): array
    {
        $wallet = ActiveWallet::get();
        $stores = $wallet ? app(ReportService::class)->stores($wallet, now())->take(12) : collect();

        return [
            'datasets' => [[
                'label' => 'Потрачено',
                'data' => $stores->map(fn ($row) => $row->total / 100)->all(),
                'backgroundColor' => '#3b82f6',
            ]],
            'labels' => $stores->pluck('store')->all(),
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }
}
