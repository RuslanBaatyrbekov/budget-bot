<?php

namespace App\Filament\Admin\Resources\WalletResource\Pages;

use App\Filament\Admin\Resources\WalletResource;
use App\Models\Wallet;
use Filament\Resources\Pages\CreateRecord;

class CreateWallet extends CreateRecord
{
    protected static string $resource = WalletResource::class;

    protected function afterCreate(): void
    {
        /** @var Wallet $wallet */
        $wallet = $this->record;

        $wallet->users()->attach(auth()->id(), ['role' => 'owner']);
        $wallet->seedDefaultCategories();
    }
}
