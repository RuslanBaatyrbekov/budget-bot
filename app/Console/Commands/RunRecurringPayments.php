<?php

namespace App\Console\Commands;

use App\Models\Wallet;
use App\Services\RecurringPaymentService;
use App\Support\Html;
use App\Support\Money;
use Illuminate\Console\Command;
use Throwable;

class RunRecurringPayments extends Command
{
    protected $signature = 'budget:recurring';

    protected $description = 'Books due recurring payments and notifies wallet members';

    public function handle(RecurringPaymentService $service): int
    {
        $created = $service->runDue();

        foreach ($created as $walletId => $transactions) {
            $wallet = Wallet::with('users')->find($walletId);

            $text = "🔁 <b>Записал регулярные платежи</b>\n\n";
            foreach ($transactions as $t) {
                $text .= ($t->category?->icon ?? '📦').' '.Html::e($t->description).' — <b>'.Money::format($t->amount, $wallet->currencySign())."</b>\n";
            }

            foreach ($wallet->users as $user) {
                try {
                    $user->telegramChat()?->html($text)->send();
                } catch (Throwable $e) {
                    report($e);
                }
            }
        }

        $this->info('Booked: '.$created->flatten()->count());

        return self::SUCCESS;
    }
}
