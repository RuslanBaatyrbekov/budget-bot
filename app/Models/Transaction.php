<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use App\Enums\TransactionType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class Transaction extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'wallet_id', 'user_id', 'category_id', 'amount', 'type',
        'description', 'store', 'batch_id', 'receipt_image_path',
        'ai_metadata', 'transaction_date', 'purchased_at', 'payment_method',
    ];

    protected static function booted(): void
    {
        static::creating(function (Transaction $transaction) {
            $transaction->batch_id ??= (string) Str::uuid();
            $transaction->transaction_date ??= now();
            $transaction->purchased_at ??= now();
        });
    }

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'type' => TransactionType::class,
            'payment_method' => PaymentMethod::class,
            'ai_metadata' => 'array',
            'transaction_date' => 'datetime',
            'purchased_at' => 'datetime',
        ];
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function scopeExpenses(Builder $query): Builder
    {
        return $query->where($query->qualifyColumn('type'), TransactionType::Expense);
    }

    public function scopeIncomes(Builder $query): Builder
    {
        return $query->where($query->qualifyColumn('type'), TransactionType::Income);
    }

    public function scopeBetweenDates(Builder $query, Carbon $from, Carbon $to): Builder
    {
        return $query->whereBetween($query->qualifyColumn('purchased_at'), [$from, $to]);
    }

    public function scopeForMonth(Builder $query, Carbon $month): Builder
    {
        return $query->betweenDates($month->copy()->startOfMonth(), $month->copy()->endOfMonth());
    }
}
