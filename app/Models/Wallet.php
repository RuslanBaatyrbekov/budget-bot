<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Wallet extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'currency'];

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'wallet_user')->withPivot('role')->withTimestamps();
    }

    public function categories(): HasMany
    {
        return $this->hasMany(Category::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function budgets(): HasMany
    {
        return $this->hasMany(Budget::class);
    }

    public function recurringPayments(): HasMany
    {
        return $this->hasMany(RecurringPayment::class);
    }

    public function invites(): HasMany
    {
        return $this->hasMany(WalletInvite::class);
    }

    public function currencySign(): string
    {
        return $this->currency ?: config('budget.default_currency');
    }

    public function seedDefaultCategories(): void
    {
        foreach (config('budget.default_categories') as $category) {
            $this->categories()->firstOrCreate(
                ['name' => $category['name']],
                ['icon' => $category['icon'], 'type' => $category['type']],
            );
        }
    }
}
