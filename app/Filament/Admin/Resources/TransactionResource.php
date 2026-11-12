<?php

namespace App\Filament\Admin\Resources;

use App\Enums\PaymentMethod;
use App\Enums\TransactionType;
use App\Filament\Admin\Resources\TransactionResource\Pages;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\Wallet;
use App\Support\ActiveWallet;
use App\Support\Money;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class TransactionResource extends Resource
{
    protected static ?string $model = Transaction::class;

    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $modelLabel = 'запись';

    protected static ?string $pluralModelLabel = 'Операции';

    protected static ?int $navigationSort = 1;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class])
            ->whereIn('wallet_id', ActiveWallet::ids());
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('wallet_id')
                ->label('Кошелёк')
                ->options(fn () => Wallet::whereKey(ActiveWallet::ids())->pluck('name', 'id'))
                ->default(fn () => ActiveWallet::id())
                ->required()
                ->live(),
            Forms\Components\ToggleButtons::make('type')
                ->label('Тип')
                ->options(TransactionType::class)
                ->default(TransactionType::Expense)
                ->inline()
                ->required()
                ->live(),
            Forms\Components\TextInput::make('amount')
                ->label('Сумма')
                ->numeric()
                ->minValue(0.01)
                ->step(0.01)
                ->required()
                ->formatStateUsing(fn ($state) => $state !== null ? $state / 100 : null)
                ->dehydrateStateUsing(fn ($state) => Money::toCents($state)),
            Forms\Components\Select::make('category_id')
                ->label('Категория')
                ->options(fn (Get $get) => Category::where('wallet_id', $get('wallet_id'))
                    ->where('type', $get('type') ?? TransactionType::Expense)
                    ->orderBy('name')
                    ->get()
                    ->mapWithKeys(fn (Category $c) => [$c->id => $c->label()]))
                ->searchable(),
            Forms\Components\TextInput::make('description')->label('Описание')->maxLength(255),
            Forms\Components\TextInput::make('store')->label('Магазин')->maxLength(255),
            Forms\Components\Select::make('payment_method')
                ->label('Оплата')
                ->options(PaymentMethod::class)
                ->default(PaymentMethod::Card)
                ->required(),
            Forms\Components\DateTimePicker::make('purchased_at')
                ->label('Дата')
                ->default(now())
                ->required(),
            Forms\Components\Hidden::make('user_id')->default(fn () => auth()->id()),
            Forms\Components\FileUpload::make('receipt_image_path')
                ->label('Чек')
                ->image()
                ->disk('public')
                ->directory('receipts')
                ->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['category', 'user', 'wallet']))
            ->defaultSort('purchased_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('purchased_at')->label('Дата')->dateTime('d.m.Y H:i')->sortable(),
                Tables\Columns\TextColumn::make('category.name')
                    ->label('Категория')
                    ->formatStateUsing(fn ($state, Transaction $record) => $record->category?->label())
                    ->badge(),
                Tables\Columns\TextColumn::make('description')->label('Описание')->searchable()->limit(40),
                Tables\Columns\TextColumn::make('store')->label('Магазин')->searchable()->toggleable(),
                Tables\Columns\TextColumn::make('amount')
                    ->label('Сумма')
                    ->sortable()
                    ->weight('bold')
                    ->color(fn (Transaction $record) => $record->type === TransactionType::Income ? 'success' : null)
                    ->formatStateUsing(fn (int $state, Transaction $record) => ($record->type === TransactionType::Income ? '+' : '').Money::format($state, $record->wallet->currencySign())),
                Tables\Columns\TextColumn::make('payment_method')->label('Оплата')->badge(),
                Tables\Columns\TextColumn::make('user.name')->label('Кто')->toggleable(),
                Tables\Columns\ImageColumn::make('receipt_image_path')->label('Чек')->disk('public')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('type')
                    ->label('Тип')
                    ->options(TransactionType::class),
                Tables\Filters\SelectFilter::make('category_id')
                    ->label('Категория')
                    ->options(fn () => Category::whereIn('wallet_id', ActiveWallet::ids())->orderBy('name')->pluck('name', 'id'))
                    ->multiple(),
                Tables\Filters\SelectFilter::make('payment_method')
                    ->label('Оплата')
                    ->options(PaymentMethod::class),
                Tables\Filters\Filter::make('purchased_at')
                    ->form([
                        Forms\Components\DatePicker::make('from')->label('С'),
                        Forms\Components\DatePicker::make('until')->label('По'),
                    ])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['from'] ?? null, fn ($q, $d) => $q->whereDate('purchased_at', '>=', $d))
                        ->when($data['until'] ?? null, fn ($q, $d) => $q->whereDate('purchased_at', '<=', $d))),
                Tables\Filters\TrashedFilter::make(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
                Tables\Actions\RestoreAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                    Tables\Actions\RestoreBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTransactions::route('/'),
            'create' => Pages\CreateTransaction::route('/create'),
            'edit' => Pages\EditTransaction::route('/{record}/edit'),
        ];
    }
}
