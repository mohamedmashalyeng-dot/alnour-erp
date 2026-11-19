<?php

namespace Tests\Unit;

use App\Modules\Catalog\Services\CodeGenerator;
use App\Modules\Core\Support\Money;
use InvalidArgumentException;
use Tests\TestCase;

class MoneyTest extends TestCase
{
    public function test_parse_and_format_without_floats(): void
    {
        $this->assertSame(125050, Money::parse('1,250.50'));
        $this->assertSame(10, Money::parse('0.1'));
        $this->assertSame(30, Money::parse('0.30'));
        $this->assertSame(1999, Money::parse('١٩٫٩٩'));
        $this->assertSame('1250.50', Money::toDecimal(125050));
        $this->assertSame('0.05', Money::toDecimal(5));
        $this->assertSame('1,250.50', Money::format(125050, false));
    }

    public function test_rejects_invalid_amounts(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Money::parse('12.345');
    }

    public function test_tax_and_rounding(): void
    {
        $this->assertSame(1500, Money::percentOf(10000, 1500));
        $this->assertSame(1304, Money::inclusiveTax(10000, 1500)); // 10000 * 15/115 = 1304.35
        $this->assertSame(2, Money::divRound(5, 3));
        $this->assertSame(-2, Money::divRound(-5, 3));
    }

    public function test_allocate_always_sums_to_total(): void
    {
        $parts = Money::allocate(100, [1, 1, 1]);
        $this->assertSame(100, array_sum($parts));
        $parts = Money::allocate(1001, [333, 333, 334]);
        $this->assertSame(1001, array_sum($parts));
    }

    public function test_ean13_check_digit(): void
    {
        $this->assertTrue(CodeGenerator::isValidEan13('4006381333931'));
        $this->assertFalse(CodeGenerator::isValidEan13('4006381333932'));
    }
}
