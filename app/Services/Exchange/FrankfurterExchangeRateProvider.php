<?php

declare(strict_types=1);

namespace App\Services\Exchange;

use Illuminate\Support\Facades\Http;
use UnexpectedValueException;

final class FrankfurterExchangeRateProvider implements ExchangeRateProvider
{
    public function usdToBrl(string $date): ExchangeRate
    {
        $response = Http::acceptJson()
            ->connectTimeout(3)
            ->timeout(8)
            ->get(rtrim(config('exchange.base_url'), '/').'/v2/providers/ecb/rate/USD/BRL', ['date' => $date])
            ->throw();

        // Quote the numeric rate token before decoding: preserve the source's decimal
        // digits instead of passing money through JSON's binary floating point.
        $body = preg_replace('/("rate"\s*:\s*)(-?\d+(?:\.\d+)?(?:[eE][+-]?\d+)?)(?=\s*[,}])/', '$1"$2"', $response->body());
        $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($data)
            || ($data['base'] ?? null) !== 'USD'
            || ($data['quote'] ?? null) !== 'BRL'
            || ! is_string($data['rate'] ?? null)
            || ! is_string($data['date'] ?? null)) {
            throw new UnexpectedValueException('A fonte retornou uma resposta de câmbio inválida.');
        }

        $rate = new ExchangeRate($data['rate'], $data['date'], 'Frankfurter / ECB');
        if ($rate->date > $date) {
            throw new UnexpectedValueException('A fonte retornou uma cotação posterior à data da despesa.');
        }

        return $rate;
    }
}
