<?php

namespace Tests\Feature;

use App\Filament\Admin\Resources\TransactionResource;
use App\Models\Transaction;
use App\Models\User;
use App\Support\ActiveWallet;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class WebPanelAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_users_see_only_their_wallets(): void
    {
        [$me, $myWallet] = $this->family();
        [$stranger, $foreignWallet] = $this->family(555);
        Transaction::factory()->create(['wallet_id' => $myWallet->id, 'user_id' => $me->id]);
        Transaction::factory()->create(['wallet_id' => $foreignWallet->id, 'user_id' => $stranger->id]);

        $this->actingAs($me);
        session([ActiveWallet::SESSION_KEY => $foreignWallet->id]);

        $this->assertSame($myWallet->id, ActiveWallet::id());
        $this->assertSame([$myWallet->id], TransactionResource::getEloquentQuery()->pluck('wallet_id')->unique()->values()->all());
    }

    public function test_panel_requires_a_wallet(): void
    {
        [$me] = $this->family();
        $panel = Filament::getPanel('admin');

        $this->assertTrue($me->canAccessPanel($panel));
        $this->assertFalse(User::factory()->create()->canAccessPanel($panel));
    }

    public function test_login_link_works_once(): void
    {
        [$me] = $this->family();
        $url = URL::temporarySignedRoute('login.telegram', now()->addMinutes(15), ['user' => $me->id, 'nonce' => 'abc']);

        $this->get($url)->assertRedirect('/admin');
        $this->assertAuthenticatedAs($me);

        auth()->logout();
        $this->get($url)->assertForbidden();
    }

    public function test_login_link_must_be_signed(): void
    {
        [$me] = $this->family();

        $this->get("/login/telegram/{$me->id}?nonce=x")->assertForbidden();
        $this->assertGuest();
    }
}
