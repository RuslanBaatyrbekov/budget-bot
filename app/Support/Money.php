<?php

namespace App\Support;

final class Money
{
    /** Converts a decimal amount (2.3, "4,50") to integer cents without float drift. */
    public static function toCents(float|int|string $amount): int
    {
        if (is_string($amount)) {
            $amount = (float) str_replace([' ', ','], ['', '.'], $amount);
        }

        return (int) round($amount * 100);
    }

    public static function format(int $cents, string $currency = '€'): string
    {
        return number_format($cents / 100, 2, ',', ' ').' '.$currency;
    }

    /** Parses user input like "400", "12,50", "1 200.5". Returns null if it is not a positive number. */
    public static function parse(string $input): ?int
    {
        $normalized = str_replace([' ', ','], ['', '.'], trim($input));

        if (! preg_match('/^\d+(\.\d{1,2})?$/', $normalized)) {
            return null;
        }

        return self::toCents($normalized);
    }
}
