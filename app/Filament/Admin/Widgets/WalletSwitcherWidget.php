<?php

namespace App\Filament\Admin\Widgets;

use App\Models\Wallet;
use App\Support\ActiveWallet;
use Filament\Forms\Components\Select;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Widgets\Widget;

class WalletSwitcherWidget extends Widget implements HasForms
{
    use InteractsWithForms;

    protected static string $view = 'filament.admin.widgets.wallet-switcher-widget';

    protected int|string|array $columnSpan = 'full';

    public ?array $data = [];

    public static function canView(): bool
    {
        return ActiveWallet::ids()->count() > 1;
    }

    public function mount(): void
    {
        $this->form->fill(['wallet_id' => ActiveWallet::id()]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Select::make('wallet_id')
                    ->label('📂 Кошелёк')
                    ->options(fn () => Wallet::whereKey(ActiveWallet::ids())->pluck('name', 'id'))
                    ->live()
                    ->afterStateUpdated(function ($state) {
                        ActiveWallet::set((int) $state);
                        $this->redirect(request()->header('Referer') ?? '/admin');
                    })
                    ->selectablePlaceholder(false),
            ])
            ->statePath('data');
    }
}
