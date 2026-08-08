<?php

namespace Tests\Feature;

use App\Jobs\ParseTextJob;
use App\Models\User;
use App\Models\Wallet;
use DefStudio\Telegraph\Models\TelegraphBot;
use DefStudio\Telegraph\Models\TelegraphChat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class TelegramWebhookTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_dispatches_job_on_text_message(): void
    {
        Queue::fake();

        Log::shouldReceive('error')->never();
        Log::shouldReceive('warning')->never();

        $bot = TelegraphBot::factory()->create(['token' => 'test-token']);
        $chat = TelegraphChat::factory()->create([
            'telegraph_bot_id' => $bot->id,
            'chat_id' => '123456789'
        ]);

        $wallet = Wallet::factory()->create();

        $user = User::factory()->create([
            'telegram_id' => '123456789',
            'current_wallet_id' => $wallet->id
        ]);

        $payload = [
            'update_id' => 10000,
            'message' => [
                'message_id' => 1,
                'from' => [
                    'id' => 123456789,
                    'is_bot' => false,
                    'first_name' => 'TestUser',
                ],
                'chat' => [
                    'id' => 123456789,
                    'type' => 'private',
                ],
                'date' => time(),
                'text' => 'Купил кофе за 5 евро',
            ]
        ];

        $response = $this->postJson("/telegraph/{$bot->token}/webhook", $payload);
        $response->assertSuccessful();

        Queue::assertPushed(ParseTextJob::class);
    }
}
