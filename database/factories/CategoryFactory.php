<?php

namespace Database\Factories;

use App\Enums\TransactionType;
use App\Models\Category;
use App\Models\Wallet;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Category> */
class CategoryFactory extends Factory
{
    public function definition(): array
    {
        return [
            'wallet_id' => Wallet::factory(),
            'name' => $this->faker->unique()->word(),
            'icon' => '📦',
            'type' => TransactionType::Expense,
            'is_active' => true,
        ];
    }

    public function income(): static
    {
        return $this->state(['type' => TransactionType::Income]);
    }
}
