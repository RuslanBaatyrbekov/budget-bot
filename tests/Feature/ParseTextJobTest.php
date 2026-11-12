<?php

namespace Tests\Feature;

use App\Enums\PaymentMethod;
use App\Enums\TransactionType;
use App\Exceptions\AiException;
use App\Jobs\ParseTextJob;
use App\Models\Transaction;
use DefStudio\Telegraph\Facades\Telegraph;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ParseTextJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_records_expense_with_exact_cents(): void
    {
        [$user, $wallet, $chat] = $this->family();

        $this->fakeGemini([
            'type' => 'expense',
            'store_name' => 'Kaufland',
            'date' => null,
            'payment_method' => 'card',
            'items' => [$this->item(2.3, 'Молоко'), $this->item(19.99, 'Сыр')],
        ]);

        dispatch_sync(new ParseTextJob($user->id, $chat->id, 'молоко 2.30 и сыр 19.99 в кауфланде картой'));

        $this->assertDatabaseHas('transactions', ['description' => 'Молоко', 'amount' => 230, 'store' => 'Kaufland', 'payment_method' => 'card']);
        $this->assertDatabaseHas('transactions', ['description' => 'Сыр', 'amount' => 1999]);
        $this->assertSame(1, Transaction::distinct()->count('batch_id'));
        Telegraph::assertSent('Записал расход', exact: false);

        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'generativelanguage')
            && $r->hasHeader('x-goog-api-key', 'test-key')
            && ! str_contains($r->url(), 'key='));
    }

    public function test_it_reuses_existing_category_ignoring_case(): void
    {
        [$user, $wallet, $chat] = $this->family();
        $before = $wallet->categories()->count();

        $this->fakeGemini(['type' => 'expense', 'payment_method' => 'cash', 'items' => [$this->item(5, 'Хлеб', ' продукты ')]]);

        dispatch_sync(new ParseTextJob($user->id, $chat->id, 'хлеб 5'));

        $this->assertSame($before, $wallet->categories()->count());
        $this->assertSame('Продукты', Transaction::first()->category->name);
    }

    public function test_it_records_income(): void
    {
        [$user, $wallet, $chat] = $this->family();

        $this->fakeGemini(['type' => 'income', 'payment_method' => 'card', 'items' => [$this->item(2500, 'Зарплата', 'Зарплата', '💵')]]);

        dispatch_sync(new ParseTextJob($user->id, $chat->id, 'зарплата 2500'));

        $transaction = Transaction::first();
        $this->assertSame(TransactionType::Income, $transaction->type);
        $this->assertSame(250000, $transaction->amount);
        Telegraph::assertSent('Записал доход', exact: false);
    }

    public function test_it_asks_for_payment_method_when_unknown(): void
    {
        [$user, $wallet, $chat] = $this->family();

        $this->fakeGemini(['type' => 'expense', 'payment_method' => 'unknown', 'items' => [$this->item(3.5, 'Кофе', 'Кафе и доставка', '☕')]]);

        dispatch_sync(new ParseTextJob($user->id, $chat->id, 'кофе 3.50'));

        $this->assertSame(PaymentMethod::Unknown, Transaction::first()->payment_method);
        Telegraph::assertSent('Как оплачивали', exact: false);
    }

    public function test_it_answers_when_there_is_no_amount(): void
    {
        [$user, $wallet, $chat] = $this->family();

        $this->fakeGemini(['type' => 'expense', 'payment_method' => 'unknown', 'items' => []]);

        dispatch_sync(new ParseTextJob($user->id, $chat->id, 'привет'));

        $this->assertDatabaseCount('transactions', 0);
        Telegraph::assertSent('Не нашёл сумму', exact: false);
    }

    public function test_it_is_released_on_rate_limit(): void
    {
        [$user, $wallet, $chat] = $this->family();
        $this->fakeGemini([], 429);

        $job = (new ParseTextJob($user->id, $chat->id, 'кофе 3'))->withFakeQueueInteractions();
        app()->call([$job, 'handle']);

        $job->assertReleased(60);
        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_api_errors_are_thrown_so_the_job_is_retried(): void
    {
        [$user, $wallet, $chat] = $this->family();
        $this->fakeGemini([], 500);

        $this->expectException(AiException::class);

        app()->call([new ParseTextJob($user->id, $chat->id, 'кофе 3'), 'handle']);
    }
}
