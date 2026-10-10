<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Money\DecimalMoney;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DecimalMoneyTest extends TestCase
{
    public function test_decimal_boundary_values_do_not_use_floating_point(): void
    {
        $this->assertSame(1, DecimalMoney::toMinor('0.01'));
        $this->assertSame(10, DecimalMoney::toMinor('0.1'));
        $this->assertSame(100, DecimalMoney::toMinor('1'));
        $this->assertSame(99999999999, DecimalMoney::toMinor('999999999.99'));
        $this->assertSame('999999999.99', DecimalMoney::format(99999999999));
        $this->assertSame('0.01', DecimalMoney::format(1));
    }

    public function test_conversion_rounds_the_total_half_up_once(): void
    {
        $this->assertSame(2, DecimalMoney::convert(1, '1.5'));
        $this->assertSame(1, DecimalMoney::convert(1, '1.49999999'));
        $this->assertSame(570960, DecimalMoney::convert(100000, '5.70960000'));
        $this->assertSame(0, DecimalMoney::convert(1, '0.00000001'));
        $this->assertSame(599999998994, DecimalMoney::convert(99999999999, '5.99999999'));
    }

    #[DataProvider('invalidDecimals')]
    public function test_rejects_ambiguous_or_out_of_range_amounts(string $amount): void
    {
        $this->expectException(InvalidArgumentException::class);
        DecimalMoney::toMinor($amount);
    }

    public static function invalidDecimals(): array
    {
        return array_map(fn ($value) => [$value], ['1,50', '1.001', '-1', '1e3', '01.00', '1000000000', 'NaN', ' 1', '.50']);
    }
}
