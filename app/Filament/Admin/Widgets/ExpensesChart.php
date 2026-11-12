<?php

namespace App\Filament\Admin\Widgets;

use App\Models\Transaction;
use App\Support\ActiveWallet;
use Filament\Widgets\ChartWidget;
use Flowframe\Trend\Trend;
use Flowframe\Trend\TrendValue;
use Illuminate\Support\Carbon;

class ExpensesChart extends ChartWidget
{
    protected static ?string $heading = 'Динамика расходов';

    protected static ?int $sort = 1;

    public ?string $filter = 'month';

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
        $trend = Trend::query(
            Transaction::where('wallet_id', ActiveWallet::id())->expenses()
        )->dateColumn('purchased_at');

        [$data, $format] = match ($this->filter) {
            'week' => [$trend->between(now()->subDays(6)->startOfDay(), now())->perDay()->sum('amount'), 'd.m'],
            'year' => [$trend->between(now()->subMonths(11)->startOfMonth(), now())->perMonth()->sum('amount'), 'm.Y'],
            default => [$trend->between(now()->subDays(29)->startOfDay(), now())->perDay()->sum('amount'), 'd.m'],
        };

        return [
            'datasets' => [[
                'label' => 'Потрачено',
                'data' => $data->map(fn (TrendValue $value) => $value->aggregate / 100)->all(),
                'borderColor' => '#ef4444',
                'backgroundColor' => 'rgba(239, 68, 68, 0.1)',
                'fill' => true,
                'tension' => 0.3,
            ]],
            'labels' => $data->map(fn (TrendValue $value) => Carbon::parse($value->date)->format($format))->all(),
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }
}
