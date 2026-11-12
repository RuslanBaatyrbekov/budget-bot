<?php

namespace App\Data;

use App\Enums\PaymentMethod;
use App\Enums\TransactionType;
use Illuminate\Support\Carbon;

final readonly class ParsedExpense
{
    /** @param list<ParsedItem> $items */
    public function __construct(
        public TransactionType $type,
        public array $items,
        public ?string $store = null,
        public ?Carbon $date = null,
        public PaymentMethod $paymentMethod = PaymentMethod::Unknown,
    ) {}

    public function isEmpty(): bool
    {
        return $this->items === [];
    }

    public function total(): int
    {
        return array_sum(array_map(fn (ParsedItem $item) => $item->amount, $this->items));
    }
}
