<?php

declare(strict_types=1);

namespace App\Services\Exchange;

use DateTimeImmutable;
use UnexpectedValueException;

final readonly class ExchangeRate
{
    public function __construct(public string $rate, public string $date, public string $source)
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (! preg_match('/\A\d{1,7}(?:\.\d{1,8})?\z/', $rate)
            || bccomp($rate, '0', 8) <= 0
            || bccomp($rate, '1000000', 8) > 0
            || ! $parsed || $parsed->format('Y-m-d') !== $date
            || trim($source) === '' || strlen($source) > 120) {
            throw new UnexpectedValueException('A fonte retornou uma cotação inválida.');
        }
    }
}
