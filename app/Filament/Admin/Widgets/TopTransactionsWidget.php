<?php

namespace App\Filament\Widgets;

use App\Models\Transaction;
use App\Models\Wallet;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

class TopTransactionsWidget extends BaseWidget
{
    // Будет отображаться под графиками
    protected static ?int $sort = 3;

    // Растягиваем таблицу на всю ширину экрана
    protected int | string | array $columnSpan = 'full';

    protected static ?string $heading = '🏆 Топ-5 самых крупных расходов за месяц';

    public function table(Table $table): Table
    {
        $currency = Wallet::first()->currency ?? 'сом';

        return $table
            ->query(
                Transaction::query()
                    ->where('type', 'expense')
                    ->where('purchased_at', '>=', now()->startOfMonth())
                    ->orderByDesc('amount')
                    ->limit(5)
            )
            ->columns([
                Tables\Columns\TextColumn::make('purchased_at')
                    ->label('Дата')
                    ->dateTime('d.m.Y H:i'),

                Tables\Columns\TextColumn::make('category.name')
                    ->label('Категория')
                    ->badge()
                    ->icon(fn ($record) => $record->category ? 'heroicon-m-tag' : null),

                Tables\Columns\TextColumn::make('description')
                    ->label('Описание'),

                Tables\Columns\TextColumn::make('amount')
                    ->label('Сумма')
                    ->formatStateUsing(fn ($state) => number_format($state / 100, 2, '.', ' ') . ' ' . $currency)
                    ->color('danger')
                    ->weight('bold'),
            ])
            ->paginated(false); // Отключаем пагинацию, так как нам нужно ровно 5 записей
    }
}
