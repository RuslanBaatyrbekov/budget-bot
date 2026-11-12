<?php

namespace App\Support;

use App\Models\User;
use App\Models\Wallet;
use Illuminate\Support\Collection;

/** The wallet selected in the web panel. Always one of the signed-in user's wallets. */
final class ActiveWallet
{
    public const SESSION_KEY = 'active_wallet_id';

    /** @return Collection<int, int> */
    public static function ids(): Collection
    {
        $user = auth()->user();

        return $user instanceof User ? $user->wallets()->pluck('wallets.id') : collect();
    }

    public static function id(): ?int
    {
        $allowed = self::ids();
        $candidates = [session(self::SESSION_KEY), auth()->user()?->current_wallet_id];

        foreach ($candidates as $id) {
            if ($id !== null && $allowed->contains((int) $id)) {
                return (int) $id;
            }
        }

        return $allowed->first();
    }

    public static function get(): ?Wallet
    {
        $id = self::id();

        return $id ? Wallet::find($id) : null;
    }

    public static function set(int $walletId): void
    {
        if (self::ids()->contains($walletId)) {
            session([self::SESSION_KEY => $walletId]);
        }
    }

    public static function currency(): string
    {
        return self::get()?->currencySign() ?? config('budget.default_currency');
    }
}
