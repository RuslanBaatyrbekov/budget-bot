<?php

namespace Tests\Feature;

use App\Jobs\ParseTextJob;
use App\Models\User;
use App\Models\Wallet;
use DefStudio\Telegraph\Models\TelegraphBot;
use DefStudio\Telegraph\Models\TelegraphChat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ParseTextJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_correctly_parses_text_and_creates_transaction(): void
    {
        $wallet = Wallet::factory()->create();

        $user = User::factory()->create([
            'current_wallet_id' => $wallet->id
        ]);

        $user->wallets()->attach($wallet->id, ['role' => 'owner']);

        $bot = TelegraphBot::factory()->create();
        $chat = TelegraphChat::factory()->create([
            'telegraph_bot_id' => $bot->id,
            'chat_id' => '123456789'
        ]);

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [
                    [
                        'content' => [
                            'parts' => [
                                [
                                    'text' => json_encode([
                                        'store_name' => 'Kaufland',
                                        'date' => now()->format('Y-m-d H:i:s'),
                                        'payment_method' => 'card',
                                        'items' => [
                                            [
                                                'amount' => 15.50,
                                                'description' => 'Продукты на неделю',
                                                'category_name' => 'Продукты',
                                                'category_icon' => '🛒',
                                            ]
                                        ]
                                    ])
                                ]
                            ]
                        ]
                    ]
                ]
            ], 200)
        ]);

        $job = new ParseTextJob($user->id, $chat->id, 'Вчера купил продукты в Kaufland на 15.50 евро по карте');
        $job->handle();

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'generativelanguage.googleapis.com');
        });

        $this->assertDatabaseHas('categories', [
            'wallet_id' => $wallet->id,
            'name' => 'Продукты',
            'icon' => '🛒'
        ]);

        $this->assertDatabaseHas('transactions', [
            'user_id' => $user->id,
            'wallet_id' => $wallet->id,
            'amount' => 1550,
            'payment_method' => 'card',
            'description' => 'Продукты на неделю'
        ]);
    }
}
