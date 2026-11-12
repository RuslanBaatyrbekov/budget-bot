<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum PaymentMethod: string implements HasColor, HasLabel
{
    case Cash = 'cash';
    case Card = 'card';
    case Unknown = 'unknown';

    public static function fromAi(?string $value): self
    {
        return self::tryFrom(strtolower(trim((string) $value))) ?? self::Unknown;
    }

    public function getLabel(): string
    {
        return $this->label();
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Cash => 'success',
            self::Card => 'info',
            self::Unknown => 'gray',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Cash => '💵 Наличными',
            self::Card => '💳 Картой',
            self::Unknown => '❓ Неизвестно',
        };
    }
}
