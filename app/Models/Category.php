<?php

namespace App\Models;

use App\Enums\TransactionType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Category extends Model
{
    use HasFactory;

    protected $fillable = ['wallet_id', 'name', 'icon', 'type', 'is_active'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'type' => TransactionType::class,
        ];
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function label(): string
    {
        return trim(($this->icon ?? '').' '.$this->name);
    }
}
