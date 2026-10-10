<?php

declare(strict_types=1);

namespace App\Services\Exchange;

interface ExchangeRateProvider
{
    public function usdToBrl(string $date): ExchangeRate;
}
