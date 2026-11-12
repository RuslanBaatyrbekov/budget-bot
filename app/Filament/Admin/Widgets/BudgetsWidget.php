<?php

namespace App\Filament\Admin\Widgets;

use App\Services\BudgetService;
use App\Support\ActiveWallet;
use Filament\Widgets\ChartWidget;

class BudgetsWidget extends ChartWidget
{
    protected static ?string $heading = 'Лимиты на месяц, %';

    protected static ?int $sort = 3;

    public static function canView(): bool
    {
        return ActiveWallet::get()?->budgets()->exists() ?? false;
    }

    protected function getData(): array
    {
        $wallet = ActiveWallet::get();
        $status = $wallet ? app(BudgetService::class)->status($wallet) : collect();

        return [
            'datasets' => [[
                'label' => 'Израсходовано',
                'data' => $status->map(fn ($row) => round($row->ratio * 100))->all(),
                'backgroundColor' => $status->map(fn ($row) => match (true) {
                    $row->ratio >= 1 => '#ef4444',
                    $row->ratio >= 0.8 => '#f59e0b',
                    default => '#22c55e',
                })->all(),
            ]],
            'labels' => $status->map(fn ($row) => $row->budget->label())->all(),
        ];
    }

    protected function getOptions(): array
    {
        return [
            'indexAxis' => 'y',
            'plugins' => ['legend' => ['display' => false]],
            'scales' => ['x' => ['suggestedMax' => 100]],
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }
}
