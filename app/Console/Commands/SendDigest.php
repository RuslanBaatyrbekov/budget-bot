<?php

namespace App\Console\Commands;

use App\Models\Wallet;
use App\Services\BudgetService;
use App\Services\ReportService;
use App\Support\Html;
use App\Support\Money;
use App\Telegram\Messages;
use Illuminate\Console\Command;
use Throwable;

class SendDigest extends Command
{
    protected $signature = 'budget:digest {period=week : week|month}';

    protected $description = 'Sends the weekly digest or the summary of the previous month to every wallet member';

    public function handle(ReportService $reports, BudgetService $budgets): int
    {
        $period = $this->argument('period');

        Wallet::with('users')->has('users')->each(function (Wallet $wallet) use ($period, $reports, $budgets) {
            $text = $period === 'month'
                ? $this->monthly($wallet, $reports)
                : $this->weekly($wallet, $reports, $budgets);

            if ($text === null) {
                return;
            }

            foreach ($wallet->users as $user) {
                try {
                    $user->telegramChat()?->html($text)->send();
                } catch (Throwable $e) {
                    report($e);
                }
            }
        });

        return self::SUCCESS;
    }

    private function weekly(Wallet $wallet, ReportService $reports, BudgetService $budgets): ?string
    {
        $from = now()->startOfWeek();
        $report = $reports->period($wallet, $from, now()->endOfWeek());

        if ($report['expense'] === 0) {
            return null;
        }

        $currency = $wallet->currencySign();
        $text = '🗓 <b>Итоги недели</b> ('.$from->format('d.m').'–'.now()->format('d.m').")\n\n"
            .'💸 Потрачено: <b>'.Money::format($report['expense'], $currency).'</b>'
            .Messages::comparison($report['expense'], $report['previous_expense'])."\n\n<b>Топ категорий:</b>\n";

        foreach ($report['categories']->take(3) as $row) {
            $text .= ($row->icon ?? '🤷').' '.Html::e($row->name).': '.Money::format($row->total, $currency)."\n";
        }

        $status = $budgets->status($wallet);
        if ($status->isNotEmpty()) {
            $text .= "\n<b>Лимиты месяца:</b>\n";
            foreach ($status as $row) {
                $text .= Html::progressBar($row->ratio, 8).' '.(int) round($row->ratio * 100).'% '.Html::e($row->budget->label())."\n";
            }
        }

        return $text;
    }

    private function monthly(Wallet $wallet, ReportService $reports): ?string
    {
        $month = now()->subMonthNoOverflow()->startOfMonth();
        $report = $reports->month($wallet, $month);

        if ($report['expense'] === 0 && $report['income'] === 0) {
            return null;
        }

        return Messages::monthReport($wallet, $month, $report);
    }
}
