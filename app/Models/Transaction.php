<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Transaction extends Model
{
    use SoftDeletes;
    use HasFactory;

    protected $fillable = [
        'wallet_id', 'user_id', 'category_id', 'amount',
        'type', 'description', 'receipt_image_path',
        'ai_metadata', 'transaction_date', 'purchased_at','payment_method',
    ];

    protected function casts(): array
    {
        return [
            'ai_metadata' => 'array',
            'transaction_date' => 'datetime',
            'purchased_at' => 'datetime',
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
