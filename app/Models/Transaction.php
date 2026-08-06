<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Transaction extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'wallet_id', 'user_id', 'category_id', 'amount',
        'type', 'description', 'receipt_image_path',
        'ai_metadata', 'transaction_date'
    ];

    protected function casts(): array
    {
        return [
            'ai_metadata' => 'array',
            'transaction_date' => 'datetime',
        ];
    }

    public function wallet()
    {
        return $this->belongsTo(Wallet::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function category()
    {
        return $this->belongsTo(Category::class);
    }
}
