<?php

namespace App\Telegram;

use App\Data\ParsedExpense;
use App\Enums\PaymentMethod;
use App\Enums\TransactionType;
use App\Models\Transaction;
use App\Models\Wallet;
use App\Support\Html;
use App\Support\Money;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/** Builds the HTML texts the bot sends. All user/AI provided strings are escaped here. */
final class Messages
{
    /**
     * @param  Collection<int, Transaction>  $transactions
     * @param  Collection<int, object>  $alerts
     */
    public static function recorded(Wallet $wallet, ParsedExpense $expense, Collection $transactions, Collection $alerts, ?string $heard = null): string
    {
        $currency = $wallet->currencySign();
        $isIncome = $expense->type === TransactionType::Income;

        $text = $heard !== null ? '🎙 <i>«'.Html::e($heard)."»</i>\n\n" : '';
        $text .= $isIncome ? '✅ Записал доход' : '✅ Записал расход';
        $text .= $expense->store ? ' в <b>'.Html::e($expense->store).'</b>' : '';

        if ($expense->date && ! $expense->date->isToday()) {
            $text .= ' за '.$expense->date->format('d.m.Y');
        }

        $text .= "\n\n";

        foreach ($transactions as $transaction) {
            $text .= ($transaction->category?->icon ?? '📦').' '.Html::e($transaction->description)
                .' — <b>'.Money::format($transaction->amount, $currency)."</b>\n";
        }

        if ($transactions->count() > 1) {
            $text .= "\nИтого: <b>".Money::format($expense->total(), $currency).'</b>';
        }

        if (! $isIncome && $expense->paymentMethod !== PaymentMethod::Unknown) {
            $text .= "\n".$expense->paymentMethod->label();
        }

        if (! $isIncome && $expense->paymentMethod === PaymentMethod::Unknown) {
            $text .= "\n\n❓ Как оплачивали?";
        }

        foreach ($alerts as $alert) {
            $text .= "\n\n".self::budgetAlert($alert, $currency);
        }

        return $text;
    }

    public static function budgetAlert(object $alert, string $currency): string
    {
        $icon = $alert->threshold >= 1 ? '🚨' : '⚠️';
        $percent = (int) round($alert->ratio * 100);

        return "{$icon} <b>Лимит «".Html::e($alert->budget->label())."»: {$percent}%</b>\n"
            .Money::format($alert->spent, $currency).' из '.Money::format($alert->budget->amount, $currency);
    }

    /** @param array<string, mixed> $report */
    public static function monthReport(Wallet $wallet, CarbonInterface $month, array $report): string
    {
        $currency = $wallet->currencySign();
        $text = '📊 <b>Отчёт за '.self::monthName($month)."</b>\n\n";

        if ($report['expense'] === 0 && $report['income'] === 0) {
            return $text.'В этом месяце записей нет 💸';
        }

        foreach ($report['categories'] as $row) {
            $share = $report['expense'] > 0 ? (int) round($row->total / $report['expense'] * 100) : 0;
            $text .= ($row->icon ?? '🤷').' '.Html::e($row->name).': <b>'.Money::format($row->total, $currency)."</b> <i>({$share}%)</i>\n";
        }

        $payments = $report['payments'];
        if ($payments->isNotEmpty()) {
            $text .= "\n<b>Оплата:</b>\n";
            foreach (PaymentMethod::cases() as $method) {
                if (($payments[$method->value] ?? 0) > 0) {
                    $text .= $method->label().': '.Money::format($payments[$method->value], $currency)."\n";
                }
            }
        }

        if ($report['users']->count() > 1) {
            $text .= "\n<b>Кто сколько:</b>\n";
            foreach ($report['users'] as $row) {
                $text .= '👤 '.Html::e($row->name).': '.Money::format($row->total, $currency)."\n";
            }
        }

        $text .= "\n💸 Расходы: <b>".Money::format($report['expense'], $currency).'</b>';
        $text .= self::comparison($report['expense'], $report['previous_expense']);

        if ($report['income'] > 0) {
            $balance = $report['income'] - $report['expense'];
            $text .= "\n💰 Доходы: <b>".Money::format($report['income'], $currency).'</b>';
            $text .= "\n".($balance >= 0 ? '🟢' : '🔴').' Баланс: <b>'.Money::format($balance, $currency).'</b>';
        }

        return $text;
    }

    /** @param Collection<int, object> $stores */
    public static function storesReport(Wallet $wallet, CarbonInterface $month, Collection $stores): string
    {
        $currency = $wallet->currencySign();
        $text = '🏪 <b>Магазины за '.self::monthName($month)."</b>\n\n";

        if ($stores->isEmpty()) {
            return $text.'В этом месяце расходов нет 💸';
        }

        foreach ($stores as $row) {
            $text .= ($row->store === 'Без магазина' ? '✍️' : '🏬').' '.Html::e($row->store).': <b>'.Money::format($row->total, $currency)."</b>\n";
        }

        return $text."\nИтого: <b>".Money::format($stores->sum('total'), $currency).'</b>';
    }

    /** @param Collection<int, object> $status */
    public static function limits(Wallet $wallet, Collection $status): string
    {
        $currency = $wallet->currencySign();

        if ($status->isEmpty()) {
            return "🎯 <b>Лимиты не заданы</b>\n\n"
                ."Задать лимит на месяц:\n<code>/limit Продукты 400</code>\n<code>/limit 2000</code> — на все расходы\n"
                .'<code>/limit Продукты 0</code> — убрать лимит';
        }

        $text = '🎯 <b>Лимиты на '.self::monthName(now())."</b>\n\n";

        foreach ($status as $row) {
            $icon = $row->ratio >= 1 ? '🔴' : ($row->ratio >= 0.8 ? '🟡' : '🟢');
            $text .= "{$icon} ".Html::e($row->budget->label())."\n"
                .Html::progressBar($row->ratio).' '.(int) round($row->ratio * 100)."%\n"
                .Money::format($row->spent, $currency).' из '.Money::format($row->budget->amount, $currency)."\n\n";
        }

        return rtrim($text);
    }

    public static function comparison(int $current, int $previous): string
    {
        if ($previous <= 0) {
            return '';
        }

        $diff = (int) round(($current - $previous) / $previous * 100);

        return match (true) {
            $diff > 0 => " <i>(+{$diff}% к прошлому периоду)</i>",
            $diff < 0 => " <i>({$diff}% к прошлому периоду)</i>",
            default => ' <i>(как в прошлом периоде)</i>',
        };
    }

    public static function monthName(CarbonInterface $date): string
    {
        return mb_convert_case($date->copy()->locale('ru')->translatedFormat('F Y'), MB_CASE_TITLE, 'UTF-8');
    }

    public static function help(): string
    {
        return <<<'HTML'
        <b>Как пользоваться</b>

        ✍️ Напиши трату текстом: <code>кофе 3,50</code>, <code>вчера REWE 42 картой</code>
        💰 Или доход: <code>зарплата 2500</code>
        📸 Пришли фото чека — разберу по позициям
        🎙 Или скажи голосом

        <b>Команды</b>
        /report — отчёт за месяц
        /stores — траты по магазинам
        /limits — лимиты на месяц, <code>/limit Продукты 400</code>
        /regular — регулярные платежи, <code>/regular 850 Аренда 1</code>
        /export — выгрузка месяца в CSV
        /undo — удалить последнюю запись
        /invite — пригласить в общий бюджет
        /currency — валюта кошелька
        /web — ссылка для входа в веб-панель
        HTML;
    }
}
