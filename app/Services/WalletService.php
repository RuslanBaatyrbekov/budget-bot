<?php

namespace App\Services;

use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletInvite;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class WalletService
{
    public function ensureWallet(User $user): Wallet
    {
        if ($user->currentWallet) {
            return $user->currentWallet;
        }

        return DB::transaction(function () use ($user) {
            $wallet = $user->wallets()->first() ?? Wallet::create([
                'name' => 'Семейный бюджет',
                'currency' => config('budget.default_currency'),
            ]);

            if (! $user->belongsToWallet($wallet->id)) {
                $user->wallets()->attach($wallet->id, ['role' => 'owner']);
            }

            $wallet->seedDefaultCategories();
            $user->forceFill(['current_wallet_id' => $wallet->id])->save();

            return $wallet;
        });
    }

    public function createInvite(Wallet $wallet, User $creator): WalletInvite
    {
        return $wallet->invites()->create([
            'created_by' => $creator->id,
            'code' => Str::random(24),
            'expires_at' => now()->addHours(config('budget.invite_ttl_hours')),
        ]);
    }

    /** Returns the joined wallet or null if the code is wrong, used or expired. */
    public function redeemInvite(string $code, User $user): ?Wallet
    {
        return DB::transaction(function () use ($code, $user) {
            $invite = WalletInvite::where('code', trim($code))->lockForUpdate()->first();

            if (! $invite || ! $invite->isUsable()) {
                return null;
            }

            $invite->update(['used_by' => $user->id, 'used_at' => now()]);

            if (! $user->belongsToWallet($invite->wallet_id)) {
                $user->wallets()->attach($invite->wallet_id, ['role' => 'member']);
            }

            $user->forceFill(['current_wallet_id' => $invite->wallet_id])->save();

            return $invite->wallet;
        });
    }
}
