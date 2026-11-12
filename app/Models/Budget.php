<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Budget extends Model
{
    protected $fillable = ['wallet_id', 'category_id', 'amount'];

    protected function casts(): array
    {
        return ['amount' => 'integer'];
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function label(): string
    {
        return $this->category?->label() ?? '📊 Все расходы';
    }
}
