<?php

namespace Tests\Unit;

use App\Support\Money;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    #[DataProvider('amounts')]
    public function test_converts_to_cents_without_float_drift(float|string $amount, int $cents): void
    {
        $this->assertSame($cents, Money::toCents($amount));
    }

    public static function amounts(): array
    {
        return [
            'float that breaks (int) cast' => [2.3, 230],
            'another one' => [19.99, 1999],
            'string with comma' => ['4,50', 450],
            'negative discount' => [-0.25, -25],
        ];
    }

    public function test_parses_user_input(): void
    {
        $this->assertSame(40000, Money::parse('400'));
        $this->assertSame(1250, Money::parse('12,50'));
        $this->assertNull(Money::parse('abc'));
        $this->assertNull(Money::parse('-5'));
    }

    public function test_formats(): void
    {
        $this->assertSame('1 234,50 €', Money::format(123450, '€'));
    }
}
