<?php

namespace Tests;

use App\Models\User;
use App\Models\Wallet;
use DefStudio\Telegraph\Facades\Telegraph;
use DefStudio\Telegraph\Models\TelegraphBot;
use DefStudio\Telegraph\Models\TelegraphChat;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

abstract class TestCase extends BaseTestCase
{
    protected const TG_ID = 123456789;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'budget.allowed_users' => [(string) self::TG_ID, '555'],
            'services.gemini.key' => 'test-key',
        ]);

        Telegraph::fake();
        Http::preventStrayRequests();
    }

    /** @return array{User, Wallet, TelegraphChat} */
    protected function family(int $telegramId = self::TG_ID): array
    {
        $wallet = Wallet::factory()->create();
        $wallet->seedDefaultCategories();

        $user = User::factory()->create(['telegram_id' => $telegramId, 'current_wallet_id' => $wallet->id]);
        $user->wallets()->attach($wallet->id, ['role' => 'owner']);

        $bot = TelegraphBot::first() ?? TelegraphBot::factory()->create(['token' => 'test-token']);
        $chat = TelegraphChat::factory()->create(['telegraph_bot_id' => $bot->id, 'chat_id' => (string) $telegramId]);

        return [$user, $wallet, $chat];
    }

    /** Fakes a Gemini answer with the given structured payload. */
    protected function fakeGemini(array $payload, int $status = 200): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response(
                $status === 200 ? ['candidates' => [['content' => ['parts' => [['text' => json_encode($payload)]]]]]] : ['error' => 'x'],
                $status,
            ),
            'api.telegram.org/bottest-token/getFile*' => Http::response(['ok' => true, 'result' => ['file_path' => 'photos/file.jpg', 'file_size' => 1000]]),
            'api.telegram.org/file/*' => Http::response('binary-file'),
        ]);
    }

    protected function item(float $amount, string $description, string $category = 'Продукты', string $icon = '🛒'): array
    {
        return ['amount' => $amount, 'description' => $description, 'category_name' => $category, 'category_icon' => $icon];
    }

    protected function webhook(array $update): TestResponse
    {
        static $updateId = 1000;

        return $this->postJson('/telegraph/test-token/webhook', ['update_id' => ++$updateId] + $update);
    }

    protected function textUpdate(string $text, int $from = self::TG_ID): array
    {
        return ['message' => [
            'message_id' => random_int(1, 99999),
            'from' => ['id' => $from, 'is_bot' => false, 'first_name' => 'Ruslan'],
            'chat' => ['id' => $from, 'type' => 'private'],
            'date' => time(),
            'text' => $text,
        ]];
    }

    protected function callbackUpdate(string $data, int $from = self::TG_ID): array
    {
        return ['callback_query' => [
            'id' => (string) random_int(1, 99999),
            'from' => ['id' => $from, 'is_bot' => false, 'first_name' => 'Ruslan'],
            'message' => [
                'message_id' => 77,
                'chat' => ['id' => $from, 'type' => 'private'],
                'date' => time(),
                'text' => 'old',
            ],
            'data' => $data,
        ]];
    }
}
