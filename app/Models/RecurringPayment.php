<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use App\Enums\TransactionType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

class RecurringPayment extends Model
{
    protected $fillable = [
        'wallet_id', 'user_id', 'category_id', 'type', 'amount',
        'description', 'day_of_month', 'payment_method', 'last_run_on', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'day_of_month' => 'integer',
            'type' => TransactionType::class,
            'payment_method' => PaymentMethod::class,
            'last_run_on' => 'date',
            'is_active' => 'boolean',
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

    /** The payment day for the given month (31 → 30 in November, 28/29 in February). */
    public function dueDateIn(Carbon $month): Carbon
    {
        $day = min($this->day_of_month, $month->daysInMonth);

        return $month->copy()->startOfMonth()->day($day)->startOfDay();
    }

    public function isDueOn(Carbon $date): bool
    {
        if (! $this->is_active) {
            return false;
        }

        $due = $this->dueDateIn($date);

        return $date->greaterThanOrEqualTo($due)
            && ($this->last_run_on === null || $this->last_run_on->lt($due));
    }
}
