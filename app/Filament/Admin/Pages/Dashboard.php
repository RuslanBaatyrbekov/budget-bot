<?php

namespace App\Filament\Admin\Pages;

use Filament\Pages\Dashboard as BaseDashboard;
use App\Filament\Admin\Widgets\DashboardStatsWidget;
use App\Filament\Admin\Widgets\WalletSwitcherWidget;

class Dashboard extends BaseDashboard
{
    protected static ?string $title = 'Обзор бюджета';

    // Регистрируем виджеты в шапке дашборда
    protected function getHeaderWidgets(): array
    {
        return [
            WalletSwitcherWidget::class, // Наш переключатель кошельков будет самым первым сверху
            DashboardStatsWidget::class,   // Твоя статистика
        ];
    }
}
