<?php

namespace App\Telegram;

use Carbon\CarbonInterface;
use DefStudio\Telegraph\Keyboard\Button;
use DefStudio\Telegraph\Keyboard\Keyboard;

/**
 * Callback data is limited to 64 bytes by Telegram, so actions and params are short:
 * pay(b, m), del(b), delOk(b), delNo, rep(y, m), sto(y, m), cur(c), regDel(id).
 */
final class Keyboards
{
    public static function afterRecord(string $batchId, bool $askPayment): Keyboard
    {
        $keyboard = Keyboard::make();

        if ($askPayment) {
            $keyboard = $keyboard->row([
                Button::make('💵 Наличными')->action('pay')->param('b', $batchId)->param('m', 'cash'),
                Button::make('💳 Картой')->action('pay')->param('b', $batchId)->param('m', 'card'),
            ]);
        }

        return $keyboard->row([
            Button::make('🗑 Удалить')->action('del')->param('b', $batchId),
        ]);
    }

    public static function confirmDelete(string $batchId): Keyboard
    {
        return Keyboard::make()->row([
            Button::make('✅ Да, удалить')->action('delOk')->param('b', $batchId),
            Button::make('❌ Отмена')->action('delNo'),
        ]);
    }

    public static function monthPager(string $action, CarbonInterface $month): Keyboard
    {
        $prev = $month->copy()->subMonthNoOverflow();
        $next = $month->copy()->addMonthNoOverflow();

        $buttons = [
            Button::make('⬅️ '.self::monthShort($prev))->action($action)->param('y', $prev->year)->param('m', $prev->month),
        ];

        if ($month->format('Y-m') < now()->format('Y-m')) {
            $buttons[] = Button::make(self::monthShort($next).' ➡️')->action($action)->param('y', $next->year)->param('m', $next->month);
        }

        return Keyboard::make()->row($buttons);
    }

    public static function currencies(): Keyboard
    {
        return Keyboard::make()->buttons(
            collect(config('budget.currencies'))
                ->map(fn (string $sign) => Button::make($sign)->action('cur')->param('c', $sign))
                ->all()
        )->chunk(3);
    }

    private static function monthShort(CarbonInterface $date): string
    {
        return mb_convert_case($date->locale('ru')->translatedFormat('M'), MB_CASE_TITLE, 'UTF-8');
    }
}
