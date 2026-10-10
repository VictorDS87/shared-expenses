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
