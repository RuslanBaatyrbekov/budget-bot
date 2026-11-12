<?php

namespace App\Filament\Admin\Widgets;

use App\Models\Transaction;
use App\Support\ActiveWallet;
use App\Support\Money;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

class TopTransactionsWidget extends BaseWidget
{
    protected static ?int $sort = 6;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $heading = '🏆 Топ-5 крупных расходов за месяц';

    public function table(Table $table): Table
    {
        $currency = ActiveWallet::currency();

        return $table
            ->query(
                Transaction::query()
                    ->with('category')
                    ->where('wallet_id', ActiveWallet::id())
                    ->expenses()
                    ->forMonth(now())
                    ->orderByDesc('amount')
                    ->limit(5)
            )
            ->columns([
                Tables\Columns\TextColumn::make('purchased_at')->label('Дата')->dateTime('d.m.Y H:i'),
                Tables\Columns\TextColumn::make('category.name')
                    ->label('Категория')
                    ->formatStateUsing(fn ($state, Transaction $record) => $record->category?->label())
                    ->badge(),
                Tables\Columns\TextColumn::make('description')->label('Описание'),
                Tables\Columns\TextColumn::make('store')->label('Магазин'),
                Tables\Columns\TextColumn::make('amount')
                    ->label('Сумма')
                    ->formatStateUsing(fn (int $state) => Money::format($state, $currency))
                    ->color('danger')
                    ->weight('bold'),
            ])
            ->paginated(false);
    }
}
