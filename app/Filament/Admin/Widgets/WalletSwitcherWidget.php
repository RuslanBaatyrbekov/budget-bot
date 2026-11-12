<?php

namespace App\Filament\Admin\Widgets;

use App\Models\Wallet;
use Filament\Forms\Components\Select;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Widgets\Widget;

class WalletSwitcherWidget extends Widget implements HasForms
{
    use InteractsWithForms;

    protected static string $view = 'filament.admin.widgets.wallet-switcher-widget';

    protected int | string | array $columnSpan = 'full';

    public ?array $data = [];

    public function mount(): void
    {
        $user = auth()->user();

        // Если у юзера не задан кошелек, берем первый попавшийся в базе
        $activeWallet = session('active_wallet_id') ?? $user?->current_wallet_id ?? Wallet::first()?->id;

        $this->form->fill([
            'wallet_id' => $activeWallet,
        ]);
    }

    public function form(Form $form): Form
    {
        // Берем вообще все кошельки из системы, чтобы точно было из чего выбрать
        $wallets = Wallet::pluck('name', 'id')->toArray();

        return $form
            ->schema([
                Select::make('wallet_id')
                    ->label('📂 Активный кошелек для просмотра')
                    ->options($wallets)
                    ->placeholder(empty($wallets) ? 'Сначала создайте кошелек в разделе Wallets' : 'Выберите кошелек')
                    ->live()
                    ->afterStateUpdated(function ($state) {
                        session(['active_wallet_id' => $state]);
                        $this->redirect(request()->header('Referer'));
                    })
                    ->selectablePlaceholder(false),
            ])
            ->statePath('data');
    }
}
