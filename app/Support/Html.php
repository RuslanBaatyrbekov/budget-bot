<?php

namespace App\Support;

final class Html
{
    /** Escapes text for Telegram HTML parse mode. */
    public static function e(?string $text): string
    {
        return htmlspecialchars((string) $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public static function progressBar(float $ratio, int $width = 10): string
    {
        $filled = (int) round(min(max($ratio, 0), 1) * $width);

        return str_repeat('▓', $filled).str_repeat('░', $width - $filled);
    }
}
