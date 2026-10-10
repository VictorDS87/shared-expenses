<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Expenses\AllocationCalculator;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class AllocationCalculatorTest extends TestCase
{
    public function test_largest_remainder_preserves_a_single_cent(): void
    {
        $this->assertSame([1 => 0, 2 => 0, 3 => 1], (new AllocationCalculator)->allocate(1, [1 => 3333, 2 => 3333, 3 => 3334]));
    }

    public function test_ties_use_unit_id_independently_of_input_order(): void
    {
        $calculator = new AllocationCalculator;
        $this->assertSame([2 => 1, 9 => 0], $calculator->allocate(1, [9 => 5000, 2 => 5000]));
        $this->assertSame($calculator->allocate(999, [9 => 5000, 2 => 5000]), $calculator->allocate(999, [2 => 5000, 9 => 5000]));
    }

    public function test_large_converted_amount_does_not_overflow_intermediate_multiplication(): void
    {
        $this->assertSame([1 => 49999999999500000, 2 => 49999999999500000], (new AllocationCalculator)->allocate(99999999999000000, [1 => 5000, 2 => 5000]));
    }

    public function test_conservation_and_fair_rounding_hold_for_many_distributions(): void
    {
        $calculator = new AllocationCalculator;
        foreach ([0, 1, 2, 99, 101, 1234567, 99999999999] as $amount) {
            for ($first = 1; $first < 9000; $first += 137) {
                $weights = [3 => $first, 7 => 9999 - $first, 9 => 1];
                $result = $calculator->allocate($amount, $weights);
                $this->assertSame($amount, array_sum($result));
                foreach ($weights as $unit => $weight) {
                    $floor = intdiv($amount * $weight, 10000);
                    $this->assertContains($result[$unit], [$floor, $floor + 1]);
                    $this->assertGreaterThanOrEqual(0, $result[$unit]);
                }
            }
        }
    }

    public function test_rejects_weights_that_do_not_sum_to_one_hundred_percent(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new AllocationCalculator)->allocate(100, [1 => 9999]);
    }
}
