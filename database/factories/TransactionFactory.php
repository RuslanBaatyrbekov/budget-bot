<?php

namespace Database\Factories;

use App\Enums\PaymentMethod;
use App\Enums\TransactionType;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Transaction> */
class TransactionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'wallet_id' => Wallet::factory(),
            'user_id' => User::factory(),
            'category_id' => null,
            'amount' => $this->faker->numberBetween(100, 10000),
            'type' => TransactionType::Expense,
            'description' => $this->faker->words(2, true),
            'store' => null,
            'batch_id' => (string) Str::uuid(),
            'payment_method' => PaymentMethod::Card,
            'transaction_date' => now(),
            'purchased_at' => now(),
        ];
    }
}
