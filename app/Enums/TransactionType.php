<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum TransactionType: string implements HasLabel
{
    case Expense = 'expense';
    case Income = 'income';

    public function getLabel(): string
    {
        return $this->label();
    }

    public function label(): string
    {
        return match ($this) {
            self::Expense => 'Расход',
            self::Income => 'Доход',
        };
    }
}
