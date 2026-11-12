<?php

namespace App\Filament\Admin\Pages;

use App\Filament\Admin\Widgets\BudgetsWidget;
use App\Filament\Admin\Widgets\DashboardStatsWidget;
use App\Filament\Admin\Widgets\ExpensesByCategoryChart;
use App\Filament\Admin\Widgets\ExpensesChart;
use App\Filament\Admin\Widgets\StoreExpensesChart;
use App\Filament\Admin\Widgets\TopTransactionsWidget;
use App\Filament\Admin\Widgets\WalletSwitcherWidget;
use App\Filament\Admin\Widgets\WeeklyRadarChart;
use Filament\Pages\Dashboard as BaseDashboard;

class Dashboard extends BaseDashboard
{
    protected static ?string $title = 'Обзор бюджета';

    protected function getHeaderWidgets(): array
    {
        return [
            WalletSwitcherWidget::class,
            DashboardStatsWidget::class,
        ];
    }

    public function getWidgets(): array
    {
        return [
            ExpensesChart::class,
            ExpensesByCategoryChart::class,
            BudgetsWidget::class,
            StoreExpensesChart::class,
            WeeklyRadarChart::class,
            TopTransactionsWidget::class,
        ];
    }
}
