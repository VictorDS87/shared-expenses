<?php

namespace Tests\Feature;

use App\Actions\CreateExpense;
use App\Models\Allocation;
use App\Models\Unit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

final class ExpenseCreationTest extends TestCase
{
    use RefreshDatabase;

    private function payload(): array
    {
        $units = collect([1, 2])->map(fn ($index) => Unit::create(['name' => "Unit $index", 'company' => 'Test', 'slug' => "unit-$index"]));

        return [
            'description' => 'Atomic expense', 'supplier' => 'Test', 'date' => now()->toDateString(),
            'amount' => '100.01', 'currency' => 'BRL',
            'allocations' => $units->map(fn ($unit) => ['unit_id' => $unit->id, 'percentage' => '50.00'])->all(),
        ];
    }

    public function test_an_unexpected_failure_rolls_back_the_entire_expense_and_its_allocations(): void
    {
        $count = 0;
        Allocation::creating(function () use (&$count): void {
            if (++$count === 2) {
                throw new RuntimeException('Allocation storage unavailable');
            }
        });
        try {
            app(CreateExpense::class)->handle($this->payload());
            $this->fail('Expected the storage failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Allocation storage unavailable', $exception->getMessage());
            $this->assertDatabaseCount('expenses', 0);
            $this->assertDatabaseCount('allocations', 0);
        } finally {
            Allocation::flushEventListeners();
        }
    }
}
