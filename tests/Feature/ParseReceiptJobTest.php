<?php

namespace Tests\Feature;

use App\Jobs\ParseReceiptJob;
use App\Models\Transaction;
use DefStudio\Telegraph\Facades\Telegraph;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ParseReceiptJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_stores_all_receipt_items_with_discounts_and_keeps_the_photo(): void
    {
        Storage::fake('public');
        [$user, $wallet, $chat] = $this->family();

        $this->fakeGemini([
            'type' => 'expense',
            'store_name' => 'REWE',
            'date' => now()->subDay()->format('Y-m-d H:i:s'),
            'payment_method' => 'card',
            'items' => [
                $this->item(1.29, 'Молоко'),
                $this->item(3.49, 'Вода'),
                $this->item(-0.25, 'Pfand возврат'),
            ],
        ]);

        dispatch_sync(new ParseReceiptJob($user->id, $chat->id, 'file-id'));

        $this->assertSame(3, Transaction::count());
        $this->assertSame(453, (int) Transaction::sum('amount'));
        $this->assertTrue(Transaction::first()->purchased_at->isYesterday());

        $path = Transaction::first()->receipt_image_path;
        $this->assertNotNull($path);
        Storage::disk('public')->assertExists($path);

        Telegraph::assertSent('REWE', exact: false);
    }
}
