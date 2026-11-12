<?php

namespace App\Data;

final readonly class ParsedItem
{
    public function __construct(
        public int $amount,
        public string $description,
        public string $categoryName,
        public ?string $categoryIcon,
    ) {}
}
