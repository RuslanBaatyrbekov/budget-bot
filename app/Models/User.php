<?php

namespace App\Models;

use Database\Factories\UserFactory;
use DefStudio\Telegraph\Models\TelegraphChat;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;

class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected $fillable = [
        'name', 'email', 'password',
        'telegram_id', 'current_wallet_id', 'settings',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'settings' => 'array',
        ];
    }

    public static function fromTelegram(int|string $telegramId, string $firstName): self
    {
        return self::firstOrCreate(
            ['telegram_id' => $telegramId],
            [
                'name' => $firstName,
                'email' => $telegramId.'@telegram.bot',
                'password' => Str::random(40),
            ],
        );
    }

    public function wallets(): BelongsToMany
    {
        return $this->belongsToMany(Wallet::class, 'wallet_user')->withPivot('role')->withTimestamps();
    }

    public function currentWallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class, 'current_wallet_id');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function belongsToWallet(int $walletId): bool
    {
        return $this->wallets()->whereKey($walletId)->exists();
    }

    /** Private chat of this user with the bot, used for notifications. */
    public function telegramChat(): ?TelegraphChat
    {
        if (! $this->telegram_id) {
            return null;
        }

        return TelegraphChat::where('chat_id', (string) $this->telegram_id)->first();
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return $this->wallets()->exists();
    }
}
