<?php

namespace App\Filament\Admin\Widgets;

use App\Models\Transaction;
use App\Models\Wallet;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Carbon;

class DashboardStatsWidget extends BaseWidget
{
    protected static ?int $sort = 1;

    protected function getStats(): array
    {
        $user = auth()->user();

        // БЕРЕМ СТРОГО ВЫБРАННЫЙ ТОБОЙ КОШЕЛЕК ИЗ СЕССИИ (с защитой)
        $walletId = session('active_wallet_id') ?? $user?->current_wallet_id;

        // Если кошелек по какой-то причине не найден, берем первый доступный юзеру, но не "абы какой" в системе
        if (!$walletId && $user) {
            $walletId = $user->wallets()->first()?->id;
        }

        $wallet = \App\Models\Wallet::find($walletId);
        $currency = $wallet?->currency ?? 'сом';

        $startOfMonth = Carbon::now()->startOfMonth();
        $currentDay = Carbon::now()->day;

        // Запрос жестко привязан к конкретному $walletId
        $baseQuery = Transaction::where('wallet_id', $walletId)
            ->where('type', 'expense')
            ->where('purchased_at', '>=', $startOfMonth);

        $totalExpense = (clone $baseQuery)->sum('amount') / 100;
        $cashTotal = (clone $baseQuery)->where('payment_method', 'cash')->sum('amount') / 100;
        $cardTotal = (clone $baseQuery)->where('payment_method', 'card')->sum('amount') / 100;

        $averageDaily = $currentDay > 0 ? $totalExpense / $currentDay : 0;

        $maxTransaction = (clone $baseQuery)->orderByDesc('amount')->first();
        $minTransaction = (clone $baseQuery)->where('amount', '>', 0)->orderBy('amount')->first();

        $maxLabel = $maxTransaction ? $maxTransaction->description : 'Нет данных';
        $maxAmount = $maxTransaction ? number_format($maxTransaction->amount / 100, 2, '.', ' ') : '0';

        $minLabel = $minTransaction ? $minTransaction->description : 'Нет данных';
        $minAmount = $minTransaction ? number_format($minTransaction->amount / 100, 2, '.', ' ') : '0';

        return [
            Stat::make('Траты за месяц (' . ($wallet?->name ?? 'Кошелек') . ')', number_format($totalExpense, 2, '.', ' ') . ' ' . $currency)
                ->description('С ' . $startOfMonth->format('d.m.Y'))
                ->descriptionIcon('heroicon-m-wallet')
                ->color('primary'),

            Stat::make('Наличными', number_format($cashTotal, 2, '.', ' ') . ' ' . $currency)
                ->descriptionIcon('heroicon-m-banknotes')
                ->color('success'),

            Stat::make('Картой', number_format($cardTotal, 2, '.', ' ') . ' ' . $currency)
                ->descriptionIcon('heroicon-m-credit-card')
                ->color('info'),

            Stat::make('Средний расход в день', number_format($averageDaily, 2, '.', ' ') . ' ' . $currency)
                ->description('За ' . $currentDay . ' дн. этого месяца')
                ->descriptionIcon('heroicon-m-calendar-days')
                ->color('success'),

            Stat::make('Самый дорогой товар', $maxAmount . ' ' . $currency)
                ->description($maxLabel)
                ->descriptionIcon('heroicon-m-arrow-trending-up')
                ->color('danger'),

            Stat::make('Самый дешевый товар', $minAmount . ' ' . $currency)
                ->description($minLabel)
                ->descriptionIcon('heroicon-m-arrow-trending-down')
                ->color('gray'),
        ];
    }
}
