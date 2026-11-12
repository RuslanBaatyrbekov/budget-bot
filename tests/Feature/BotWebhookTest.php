<?php

namespace Tests\Feature;

use App\Jobs\ParseReceiptJob;
use App\Jobs\ParseTextJob;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletInvite;
use DefStudio\Telegraph\Facades\Telegraph;
use DefStudio\Telegraph\Models\TelegraphBot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class BotWebhookTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        TelegraphBot::factory()->create(['token' => 'test-token']);
    }

    public function test_text_message_dispatches_parse_job(): void
    {
        $this->family();

        $this->webhook($this->textUpdate('Купил кофе за 5 евро'))->assertSuccessful();

        Queue::assertPushed(ParseTextJob::class, fn (ParseTextJob $job) => $job->text === 'Купил кофе за 5 евро');
    }

    public function test_photo_dispatches_receipt_job(): void
    {
        $this->family();
        $update = $this->textUpdate('');
        unset($update['message']['text']);
        $update['message']['photo'] = [
            ['file_id' => 'small', 'file_unique_id' => 's', 'width' => 90, 'height' => 90],
            ['file_id' => 'big', 'file_unique_id' => 'b', 'width' => 1280, 'height' => 1280],
        ];

        $this->webhook($update)->assertSuccessful();

        Queue::assertPushed(ParseReceiptJob::class, fn (ParseReceiptJob $job) => $job->fileId === 'big');
    }

    public function test_unknown_users_are_rejected(): void
    {
        $this->webhook($this->textUpdate('кофе 5', from: 999))->assertSuccessful();

        Queue::assertNothingPushed();
        Telegraph::assertSent('приватный бот', exact: false);
    }

    public function test_empty_allow_list_lets_nobody_in(): void
    {
        $this->family();
        config(['budget.allowed_users' => []]);

        $this->webhook($this->textUpdate('кофе 5'));

        Queue::assertNothingPushed();
    }

    public function test_the_same_update_is_processed_once(): void
    {
        $this->family();
        $update = ['update_id' => 42] + $this->textUpdate('кофе 5');

        $this->postJson('/telegraph/test-token/webhook', $update);
        $this->postJson('/telegraph/test-token/webhook', $update);

        Queue::assertPushed(ParseTextJob::class, 1);
    }

    public function test_start_creates_user_wallet_and_default_categories(): void
    {
        $this->webhook($this->textUpdate('/start'))->assertSuccessful();

        $user = User::where('telegram_id', self::TG_ID)->first();
        $this->assertNotNull($user->currentWallet);
        $this->assertTrue($user->currentWallet->categories()->where('name', 'Продукты')->exists());
        $this->assertSame('owner', $user->wallets()->first()->pivot->role);
    }

    public function test_invite_codes_are_random_and_single_use(): void
    {
        [$owner, $wallet] = $this->family();

        $this->webhook($this->textUpdate('/invite'));
        $code = WalletInvite::firstOrFail()->code;
        $this->assertGreaterThanOrEqual(24, strlen($code));

        $this->webhook($this->textUpdate("/join {$code}", from: 555));
        $wife = User::where('telegram_id', 555)->first();
        $this->assertSame($wallet->id, $wife->current_wallet_id);

        // Second use of the same code does nothing.
        $other = Wallet::factory()->create();
        $wife->forceFill(['current_wallet_id' => $other->id])->save();
        $this->webhook($this->textUpdate("/join {$code}", from: 555));
        $this->assertSame($other->id, $wife->fresh()->current_wallet_id);
    }

    public function test_old_base64_wallet_codes_do_not_work(): void
    {
        [$owner, $wallet] = $this->family();

        $this->webhook($this->textUpdate('/join '.base64_encode((string) $wallet->id), from: 555));

        $this->assertNull(User::where('telegram_id', 555)->first()?->current_wallet_id);
        $this->assertFalse($wallet->users()->where('telegram_id', 555)->exists());
    }

    public function test_cannot_delete_records_of_another_wallet(): void
    {
        $this->family();
        [$stranger] = $this->family(555);
        $foreign = Transaction::factory()->create(['wallet_id' => $stranger->current_wallet_id, 'user_id' => $stranger->id]);

        $this->webhook($this->callbackUpdate("action:delOk;b:{$foreign->batch_id}"));

        $this->assertNotSoftDeleted($foreign);
    }

    public function test_delete_and_payment_buttons_work_for_own_records(): void
    {
        [$user, $wallet] = $this->family();
        $t = Transaction::factory()->create(['wallet_id' => $wallet->id, 'user_id' => $user->id, 'payment_method' => 'unknown']);

        $this->webhook($this->callbackUpdate("action:pay;b:{$t->batch_id};m:cash"));
        $this->assertSame('cash', $t->fresh()->payment_method->value);

        $this->webhook($this->callbackUpdate("action:delOk;b:{$t->batch_id}"));
        $this->assertSoftDeleted($t);
    }

    public function test_limit_command_sets_category_budget(): void
    {
        [$user, $wallet] = $this->family();

        $this->webhook($this->textUpdate('/limit Продукты 400'));

        $budget = $wallet->budgets()->first();
        $this->assertSame(40000, $budget->amount);
        $this->assertSame('Продукты', $budget->category->name);
    }

    public function test_report_contains_income_and_who_spent_what(): void
    {
        [$user, $wallet] = $this->family();
        [$wife] = $this->family(555);
        $wife->wallets()->attach($wallet->id);
        $wife->forceFill(['current_wallet_id' => $wallet->id, 'name' => 'Жена'])->save();
        $user->forceFill(['name' => 'Руслан'])->save();

        Transaction::factory()->create(['wallet_id' => $wallet->id, 'user_id' => $user->id, 'amount' => 1000]);
        Transaction::factory()->create(['wallet_id' => $wallet->id, 'user_id' => $wife->id, 'amount' => 2000]);
        Transaction::factory()->create(['wallet_id' => $wallet->id, 'user_id' => $user->id, 'amount' => 500000, 'type' => 'income']);

        $this->webhook($this->textUpdate('/report'));

        Telegraph::assertSent('Кто сколько', exact: false);
        Telegraph::assertSent('Баланс: <b>4 970,00 €</b>', exact: false);
    }

    public function test_export_sends_csv_document(): void
    {
        [$user, $wallet] = $this->family();
        Transaction::factory()->create(['wallet_id' => $wallet->id, 'user_id' => $user->id]);

        $this->webhook($this->textUpdate('/export'));

        Telegraph::assertSentData(\DefStudio\Telegraph\Telegraph::ENDPOINT_SEND_DOCUMENT);
        $this->assertSame([], glob(storage_path('app/private/exports/*.csv')) ?: []);
    }

    public function test_weekly_digest_is_sent_to_wallet_members(): void
    {
        [$user, $wallet] = $this->family();
        Transaction::factory()->create(['wallet_id' => $wallet->id, 'user_id' => $user->id, 'amount' => 4200]);

        $this->assertSame(0, Artisan::call('budget:digest', ['period' => 'week']));

        Telegraph::assertSent('Итоги недели', exact: false);
    }

    public function test_month_navigation_and_currency_buttons(): void
    {
        [, $wallet] = $this->family();

        $this->webhook($this->callbackUpdate('action:rep;y:2026;m:10'));
        Telegraph::assertSentData(\DefStudio\Telegraph\Telegraph::ENDPOINT_EDIT_MESSAGE, ['text' => 'Октябрь 2026'], exact: false);

        $this->webhook($this->callbackUpdate('action:cur;c:$'));
        $this->assertSame('$', $wallet->fresh()->currency);

        $this->webhook($this->callbackUpdate('action:cur;c:BTC'));
        $this->assertSame('$', $wallet->fresh()->currency);
    }
}
