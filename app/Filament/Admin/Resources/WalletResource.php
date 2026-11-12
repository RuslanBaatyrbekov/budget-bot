<?php

namespace App\Filament\Admin\Resources;

use App\Filament\Admin\Resources\WalletResource\Pages;
use App\Models\Wallet;
use App\Support\ActiveWallet;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class WalletResource extends Resource
{
    protected static ?string $model = Wallet::class;

    protected static ?string $navigationIcon = 'heroicon-o-wallet';

    protected static ?string $modelLabel = 'кошелёк';

    protected static ?string $pluralModelLabel = 'Кошельки';

    protected static ?int $navigationSort = 3;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->whereKey(ActiveWallet::ids());
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('name')->label('Название')->required()->maxLength(255),
            Forms\Components\Select::make('currency')
                ->label('Валюта')
                ->options(collect(config('budget.currencies'))->mapWithKeys(fn ($c) => [$c => $c]))
                ->default(config('budget.default_currency'))
                ->required(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('users')->withCount('transactions'))
            ->columns([
                Tables\Columns\TextColumn::make('name')->label('Название')->searchable(),
                Tables\Columns\TextColumn::make('currency')->label('Валюта'),
                Tables\Columns\TextColumn::make('users.name')->label('Участники')->badge(),
                Tables\Columns\TextColumn::make('transactions_count')->label('Записей'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListWallets::route('/'),
            'create' => Pages\CreateWallet::route('/create'),
            'edit' => Pages\EditWallet::route('/{record}/edit'),
        ];
    }
}
