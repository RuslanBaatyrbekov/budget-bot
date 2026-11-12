<?php

namespace App\Filament\Admin\Widgets;

use App\Models\Transaction;
use App\Models\Wallet;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

class TopTransactionsWidget extends BaseWidget
{
    protected static ?int $sort = 5;
    protected int | string | array $columnSpan = 'full';
    protected static ?string $heading = '🏆 Топ-5 самых крупных расходов за месяц';

    public function table(Table $table): Table
    {
        $walletId = session('active_wallet_id') ?? auth()->user()?->current_wallet_id;
        $wallet = Wallet::find($walletId);
        $currency = $wallet?->currency ?? 'сом';

        return $table
            ->query(
                Transaction::query()
                    ->where('wallet_id', $walletId)
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
            ->paginated(false);
    }
}
