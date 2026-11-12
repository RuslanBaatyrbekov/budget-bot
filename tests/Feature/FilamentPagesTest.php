<?php

namespace Tests\Feature;

use App\Filament\Admin\Resources\CategoryResource\Pages\ListCategories;
use App\Filament\Admin\Resources\TransactionResource\Pages\CreateTransaction;
use App\Filament\Admin\Resources\TransactionResource\Pages\ListTransactions;
use App\Filament\Admin\Widgets;
use App\Models\Transaction;
use App\Services\BudgetService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class FilamentPagesTest extends TestCase
{
    use RefreshDatabase;

    private function signIn(): array
    {
        [$user, $wallet] = $this->family();
        $food = $wallet->categories()->where('name', 'Продукты')->first();
        Transaction::factory()->count(3)->create(['wallet_id' => $wallet->id, 'user_id' => $user->id, 'category_id' => $food->id, 'store' => 'REWE']);
        app(BudgetService::class)->setLimit($wallet, $food, 10000);
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        return [$user, $wallet, $food];
    }

    #[DataProvider('pages')]
    public function test_pages_render(string $url): void
    {
        $this->signIn();

        $this->get($url)->assertOk();
    }

    public static function pages(): array
    {
        return [
            'dashboard' => ['/admin'],
            'transactions' => ['/admin/transactions'],
            'new transaction' => ['/admin/transactions/create'],
            'categories' => ['/admin/categories'],
            'wallets' => ['/admin/wallets'],
        ];
    }

    #[DataProvider('widgets')]
    public function test_widgets_render(string $widget): void
    {
        $this->signIn();

        Livewire::test($widget)->assertOk();
    }

    public static function widgets(): array
    {
        return collect([
            Widgets\DashboardStatsWidget::class,
            Widgets\ExpensesChart::class,
            Widgets\ExpensesByCategoryChart::class,
            Widgets\BudgetsWidget::class,
            Widgets\StoreExpensesChart::class,
            Widgets\WeeklyRadarChart::class,
            Widgets\TopTransactionsWidget::class,
        ])->mapWithKeys(fn ($w) => [class_basename($w) => [$w]])->all();
    }

    public function test_transaction_can_be_created_in_euros(): void
    {
        [$user, $wallet, $food] = $this->signIn();

        Livewire::test(CreateTransaction::class)
            ->fillForm([
                'wallet_id' => $wallet->id,
                'type' => 'expense',
                'amount' => '12.30',
                'category_id' => $food->id,
                'description' => 'Ручная запись',
                'payment_method' => 'cash',
                'purchased_at' => now()->format('Y-m-d H:i:s'),
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('transactions', ['description' => 'Ручная запись', 'amount' => 1230, 'user_id' => $user->id]);
    }

    public function test_lists_show_only_own_records(): void
    {
        [$user] = $this->signIn();
        [$stranger, $foreign] = $this->family(555);
        $alien = Transaction::factory()->create(['wallet_id' => $foreign->id, 'user_id' => $stranger->id]);
        $this->actingAs($user);

        Livewire::test(ListTransactions::class)
            ->assertCanSeeTableRecords(Transaction::where('wallet_id', $user->current_wallet_id)->get())
            ->assertCanNotSeeTableRecords([$alien]);

        Livewire::test(ListCategories::class)
            ->assertCanNotSeeTableRecords($foreign->categories);
    }
}
