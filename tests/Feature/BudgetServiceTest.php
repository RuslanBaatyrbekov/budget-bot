<?php

namespace Tests\Feature;

use App\Models\Transaction;
use App\Services\BudgetService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BudgetServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_alert_is_sent_once_per_threshold(): void
    {
        [$user, $wallet] = $this->family();
        $food = $wallet->categories()->where('name', 'Продукты')->first();
        $service = app(BudgetService::class);
        $service->setLimit($wallet, $food, 10000);

        $make = fn (int $amount) => collect([Transaction::factory()->create([
            'wallet_id' => $wallet->id, 'user_id' => $user->id, 'category_id' => $food->id, 'amount' => $amount,
        ])]);

        $this->assertCount(0, $service->alertsFor($wallet, $make(5000)));   // 50%
        $this->assertCount(1, $service->alertsFor($wallet, $make(3500)));   // 85% → warning
        $this->assertCount(0, $service->alertsFor($wallet, $make(500)));    // 90%, already warned
        $alerts = $service->alertsFor($wallet, $make(2000));                // 110% → over
        $this->assertCount(1, $alerts);
        $this->assertEquals(1.0, $alerts->first()->threshold);
    }

    public function test_zero_removes_limit(): void
    {
        [, $wallet] = $this->family();
        $service = app(BudgetService::class);

        $service->setLimit($wallet, null, 200000);
        $service->setLimit($wallet, null, 0);

        $this->assertSame(0, $wallet->budgets()->count());
    }
}
