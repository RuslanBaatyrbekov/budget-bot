<?php

namespace App\Filament\Widgets;

use App\Models\Transaction;
use Filament\Widgets\ChartWidget;
use Flowframe\Trend\Trend;
use Flowframe\Trend\TrendValue;
use Illuminate\Support\Carbon;
use Filament\Support\RawJs;

class ExpensesChart extends ChartWidget
{
    protected static ?string $heading = 'Динамика расходов';
    protected static ?int $sort = 2;

    public ?string $filter = 'week'; // Ставим фильтр "За неделю" по умолчанию при загрузке страницы

    protected function getFilters(): ?array
    {
        return [
            'today' => 'Сегодня',
            'week' => 'За неделю',
            'month' => 'За месяц',
            'year' => 'За год',
        ];
    }

    protected function getData(): array
    {
        $activeFilter = $this->filter;

        $startDate = match ($activeFilter) {
            'today' => Carbon::today(),
            'week' => Carbon::today()->subDays(7),
            'year' => Carbon::today()->subYear(),
            default => Carbon::today()->subMonth(),
        };

        // Используем новую колонку purchased_at для графика
        $query = Trend::model(Transaction::class)
            ->dateColumn('purchased_at')
            ->between(start: $startDate, end: Carbon::now());

        // Динамичная группировка, чтобы графики всегда выглядели красиво
        if ($activeFilter === 'today') {
            $data = $query->perHour()->sum('amount');
            $labels = $data->map(fn (TrendValue $value) => Carbon::parse($value->date)->format('H:i'))->toArray();
        } elseif ($activeFilter === 'year') {
            $data = $query->perMonth()->sum('amount');
            $labels = $data->map(fn (TrendValue $value) => Carbon::parse($value->date)->format('m.Y'))->toArray();
        } else {
            $data = $query->perDay()->sum('amount');
            $labels = $data->map(fn (TrendValue $value) => Carbon::parse($value->date)->format('d.m'))->toArray();
        }

        return [
            'datasets' => [
                [
                    'label' => 'Потрачено',
                    // Делим на 100, так как суммы в базе хранятся в копейках!
                    'data' => $data->map(fn (TrendValue $value) => $value->aggregate / 100)->toArray(),
                    'borderColor' => '#ef4444',
                    'backgroundColor' => 'rgba(239, 68, 68, 0.1)',
                    'fill' => true,
                ],
            ],
            'labels' => $labels,
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }
}
