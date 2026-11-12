<?php

namespace App\Filament\Admin\Widgets;

use App\Services\ReportService;
use App\Support\ActiveWallet;
use Filament\Widgets\ChartWidget;

class ExpensesByCategoryChart extends ChartWidget
{
    protected static ?string $heading = 'Траты по категориям';

    protected static ?int $sort = 2;

    public ?string $filter = 'month';

    protected function getFilters(): ?array
    {
        return [
            'week' => 'За неделю',
            'month' => 'Этот месяц',
            'prev' => 'Прошлый месяц',
            'year' => 'За год',
        ];
    }

    protected function getData(): array
    {
        $wallet = ActiveWallet::get();

        if ($wallet === null) {
            return ['datasets' => [], 'labels' => []];
        }

        [$from, $to] = match ($this->filter) {
            'week' => [now()->subDays(6)->startOfDay(), now()],
            'prev' => [now()->subMonthNoOverflow()->startOfMonth(), now()->subMonthNoOverflow()->endOfMonth()],
            'year' => [now()->subMonths(11)->startOfMonth(), now()],
            default => [now()->startOfMonth(), now()->endOfMonth()],
        };

        $categories = app(ReportService::class)->period($wallet, $from, $to)['categories'];

        return [
            'datasets' => [[
                'label' => 'Сумма',
                'data' => $categories->map(fn ($row) => $row->total / 100)->all(),
                'backgroundColor' => ['#ef4444', '#f97316', '#eab308', '#22c55e', '#06b6d4', '#3b82f6', '#8b5cf6', '#ec4899', '#64748b', '#14b8a6', '#d946ef', '#84cc16'],
            ]],
            'labels' => $categories->map(fn ($row) => trim(($row->icon ?? '').' '.$row->name))->all(),
        ];
    }

    protected function getType(): string
    {
        return 'doughnut';
    }

    protected function getOptions(): array
    {
        return ['scales' => ['x' => ['display' => false], 'y' => ['display' => false]]];
    }
}
