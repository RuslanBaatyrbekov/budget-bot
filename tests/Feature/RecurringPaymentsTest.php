<?php

namespace Tests\Feature;

use App\Models\RecurringPayment;
use App\Models\Transaction;
use App\Services\RecurringPaymentService;
use DefStudio\Telegraph\Facades\Telegraph;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class RecurringPaymentsTest extends TestCase
{
    use RefreshDatabase;

    private function payment(array $attributes = []): RecurringPayment
    {
        [$user, $wallet] = $this->family();

        return $wallet->recurringPayments()->create($attributes + [
            'user_id' => $user->id,
            'amount' => 85000,
            'description' => 'Аренда',
            'day_of_month' => 1,
            'payment_method' => 'card',
        ]);
    }

    public function test_it_books_a_due_payment_once(): void
    {
        $this->travelTo(Carbon::create(2026, 12, 1, 8));
        $this->payment();

        $this->assertSame(0, Artisan::call('budget:recurring'));
        $this->assertSame(0, Artisan::call('budget:recurring'));

        $this->assertSame(1, Transaction::count());
        $this->assertSame(85000, Transaction::first()->amount);
        Telegraph::assertSent('Записал регулярные платежи', exact: false);
    }

    public function test_day_31_is_booked_on_the_last_day_of_short_months(): void
    {
        $payment = $this->payment(['day_of_month' => 31]);
        $service = app(RecurringPaymentService::class);

        $this->assertCount(0, $service->runDue(Carbon::create(2026, 11, 29)));
        $this->assertCount(1, $service->runDue(Carbon::create(2026, 11, 30)));
        $this->assertTrue($payment->fresh()->last_run_on->isSameDay(Carbon::create(2026, 11, 30)));
    }

    public function test_missed_day_is_caught_up_later_in_the_month(): void
    {
        $this->payment(['day_of_month' => 5]);

        $this->assertCount(1, app(RecurringPaymentService::class)->runDue(Carbon::create(2026, 12, 7)));
    }

    public function test_inactive_payments_are_skipped(): void
    {
        $this->payment(['is_active' => false]);

        $this->assertCount(0, app(RecurringPaymentService::class)->runDue(Carbon::create(2026, 12, 1)));
    }
}
