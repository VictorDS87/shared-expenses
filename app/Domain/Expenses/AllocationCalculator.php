<?php

declare(strict_types=1);

namespace App\Domain\Expenses;

use InvalidArgumentException;

final class AllocationCalculator
{
    /**
     * Largest remainder method: preserve every cent, break ties by unit id.
     * BCMath also protects multiplication of a converted BIGINT by 10000.
     *
     * @param  array<int, int>  $weights  Unit id => percentage in basis points.
     * @return array<int, int> Unit id => allocated amount in cents.
     */
    public function allocate(int $totalMinor, array $weights): array
    {
        if ($totalMinor < 0 || $weights === [] || array_sum($weights) !== 10000) {
            throw new InvalidArgumentException('O rateio deve totalizar exatamente 100%.');
        }

        $amounts = [];
        $remainders = [];
        foreach ($weights as $unitId => $weight) {
            if (! is_int($unitId) || $unitId <= 0 || ! is_int($weight) || $weight <= 0 || $weight > 10000) {
                throw new InvalidArgumentException('Unidade ou percentual inválidos.');
            }

            $product = bcmul((string) $totalMinor, (string) $weight, 0);
            $amounts[$unitId] = (int) bcdiv($product, '10000', 0);
            $remainders[$unitId] = (int) bcmod($product, '10000');
        }

        uksort($remainders, fn (int $a, int $b): int => ($remainders[$b] <=> $remainders[$a]) ?: ($a <=> $b));
        $remaining = $totalMinor - array_sum($amounts);
        foreach (array_keys($remainders) as $unitId) {
            if ($remaining-- <= 0) {
                break;
            }
            $amounts[$unitId]++;
        }

        ksort($amounts);

        return $amounts;
    }
}
