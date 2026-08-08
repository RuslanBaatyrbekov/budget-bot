<?php

namespace App\Filament\Admin\Widgets;

use App\Models\Transaction;
use App\Models\Wallet;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Carbon;

class StoreExpensesChart extends ChartWidget
{
    protected static ?string $heading = 'Траты по магазинам (за месяц)';

    protected static ?int $sort = 2;

    protected function getData(): array
    {
        $startOfMonth = Carbon::now()->startOfMonth();

        $transactions = Transaction::where('type', 'expense')
            ->where('purchased_at', '>=', $startOfMonth)
            ->get();

        $storeTotals = [];

        foreach ($transactions as $transaction) {
            $metadata = is_array($transaction->ai_metadata)
                ? $transaction->ai_metadata
                : json_decode($transaction->ai_metadata ?? '{}', true);

            $store = $metadata['store'] ?? $metadata['store_name'] ?? 'Разное / Вручную';

            if (!isset($storeTotals[$store])) {
                $storeTotals[$store] = 0;
            }

            $storeTotals[$store] += $transaction->amount / 100;
        }

        arsort($storeTotals);

        return [
            'datasets' => [
                [
                    'label' => 'Потрачено',
                    'data' => array_values($storeTotals),
                    'backgroundColor' => [
                        '#3b82f6', '#ef4444', '#10b981', '#f59e0b', '#8b5cf6',
                        '#06b6d4', '#f43f5e', '#84cc16', '#64748b', '#14b8a6',
                        '#d946ef', '#0ea5e9', '#eab308', '#22c55e', '#f97316'
                    ],
                ],
            ],
            'labels' => array_keys($storeTotals),
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }
}
