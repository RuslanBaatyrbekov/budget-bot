<?php

namespace App\Filament\Admin\Resources;

use App\Enums\TransactionType;
use App\Filament\Admin\Resources\CategoryResource\Pages;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\Wallet;
use App\Support\ActiveWallet;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class CategoryResource extends Resource
{
    protected static ?string $model = Category::class;

    protected static ?string $navigationIcon = 'heroicon-o-tag';

    protected static ?string $modelLabel = 'категория';

    protected static ?string $pluralModelLabel = 'Категории';

    protected static ?int $navigationSort = 2;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->whereIn('wallet_id', ActiveWallet::ids());
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('wallet_id')
                ->label('Кошелёк')
                ->options(fn () => Wallet::whereKey(ActiveWallet::ids())->pluck('name', 'id'))
                ->default(fn () => ActiveWallet::id())
                ->required(),
            Forms\Components\TextInput::make('name')->label('Название')->required()->maxLength(60),
            Forms\Components\TextInput::make('icon')->label('Эмодзи')->maxLength(8),
            Forms\Components\ToggleButtons::make('type')
                ->label('Тип')
                ->options(TransactionType::class)
                ->default(TransactionType::Expense)
                ->inline()
                ->required(),
            Forms\Components\Toggle::make('is_active')->label('Активна')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->withCount('transactions'))
            ->defaultSort('transactions_count', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('icon')->label(''),
                Tables\Columns\TextColumn::make('name')->label('Название')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('type')->label('Тип')->badge(),
                Tables\Columns\TextColumn::make('transactions_count')->label('Записей')->sortable(),
                Tables\Columns\IconColumn::make('is_active')->label('Активна')->boolean(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('type')->label('Тип')->options(TransactionType::class),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\BulkAction::make('merge')
                        ->label('Объединить в одну')
                        ->icon('heroicon-o-arrows-pointing-in')
                        ->form(fn (Collection $records) => [
                            Forms\Components\Select::make('target')
                                ->label('Оставить категорию')
                                ->options($records->mapWithKeys(fn (Category $c) => [$c->id => $c->label()]))
                                ->required(),
                        ])
                        ->action(fn (Collection $records, array $data) => static::merge($records, (int) $data['target']))
                        ->deselectRecordsAfterCompletion(),
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    /** Moves all records of the selected categories into one and deletes the rest. */
    public static function merge(Collection $records, int $targetId): void
    {
        $target = $records->firstWhere('id', $targetId);
        $sources = $records->where('wallet_id', $target?->wallet_id)->reject(fn (Category $c) => $c->id === $targetId);

        if ($target === null || $sources->isEmpty()) {
            return;
        }

        DB::transaction(function () use ($target, $sources) {
            Transaction::withTrashed()->whereIn('category_id', $sources->modelKeys())->update(['category_id' => $target->id]);
            $target->wallet->budgets()->whereIn('category_id', $sources->modelKeys())->delete();
            $target->wallet->recurringPayments()->whereIn('category_id', $sources->modelKeys())->update(['category_id' => $target->id]);
            Category::whereKey($sources->modelKeys())->delete();
        });

        Notification::make()->title('Объединено в «'.$target->name.'»')->success()->send();
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCategories::route('/'),
            'create' => Pages\CreateCategory::route('/create'),
            'edit' => Pages\EditCategory::route('/{record}/edit'),
        ];
    }
}
