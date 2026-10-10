<?php

declare(strict_types=1);

namespace App\Domain\Money;

use InvalidArgumentException;

/** Money crosses the application boundary as decimal strings, never floats. */
final class DecimalMoney
{
    public static function toMinor(string $amount): int
    {
        if (! preg_match('/\A(?:0|[1-9]\d{0,8})(?:\.\d{1,2})?\z/', $amount)) {
            throw new InvalidArgumentException('Informe um valor decimal entre 0 e 999999999.99.');
        }

        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '');

        return ((int) $whole * 100) + (int) str_pad($fraction, 2, '0');
    }

    public static function format(int $minor): string
    {
        if ($minor < 0) {
            throw new InvalidArgumentException('O valor não pode ser negativo.');
        }

        return intdiv($minor, 100).'.'.str_pad((string) ($minor % 100), 2, '0', STR_PAD_LEFT);
    }
}
