<?php

namespace App\Filament\Admin\Widgets;

use App\Models\Transaction;
use App\Services\ReportService;
use App\Support\ActiveWallet;
use App\Support\Money;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class DashboardStatsWidget extends BaseWidget
{
    protected static ?int $sort = 1;

    protected function getStats(): array
    {
        $wallet = ActiveWallet::get();

        if ($wallet === null) {
            return [Stat::make('Нет кошелька', '—')->description('Отправь /start боту')];
        }

        $currency = $wallet->currencySign();
        $report = app(ReportService::class)->month($wallet, now());
        $day = now()->day;
        $forecast = (int) round($report['expense'] / $day * now()->daysInMonth);

        $biggest = Transaction::where('wallet_id', $wallet->id)->expenses()->forMonth(now())->orderByDesc('amount')->first();
        $diff = $report['previous_expense'] > 0
            ? (int) round(($report['expense'] - $report['previous_expense']) / $report['previous_expense'] * 100)
            : null;

        return [
            Stat::make('Расходы за '.now()->locale('ru')->translatedFormat('F'), Money::format($report['expense'], $currency))
                ->description($diff === null ? 'С '.now()->startOfMonth()->format('d.m') : ($diff >= 0 ? "+{$diff}%" : "{$diff}%").' к прошлому месяцу')
                ->descriptionIcon($diff !== null && $diff > 0 ? 'heroicon-m-arrow-trending-up' : 'heroicon-m-arrow-trending-down')
                ->color($diff !== null && $diff > 0 ? 'danger' : 'success'),

            Stat::make('Доходы', Money::format($report['income'], $currency))
                ->description('Баланс: '.Money::format($report['income'] - $report['expense'], $currency))
                ->descriptionIcon('heroicon-m-scale')
                ->color($report['income'] >= $report['expense'] ? 'success' : 'warning'),

            Stat::make('В среднем в день', Money::format(intdiv($report['expense'], $day), $currency))
                ->description('Прогноз на месяц: '.Money::format($forecast, $currency))
                ->descriptionIcon('heroicon-m-calendar-days')
                ->color('info'),

            Stat::make('Наличными / картой',
                Money::format($report['payments']['cash'] ?? 0, $currency).' / '.Money::format($report['payments']['card'] ?? 0, $currency))
                ->descriptionIcon('heroicon-m-credit-card')
                ->color('gray'),

            Stat::make('Самая крупная трата', $biggest ? Money::format($biggest->amount, $currency) : '—')
                ->description($biggest?->description ?? 'Нет данных')
                ->descriptionIcon('heroicon-m-fire')
                ->color('danger'),
        ];
    }
}
