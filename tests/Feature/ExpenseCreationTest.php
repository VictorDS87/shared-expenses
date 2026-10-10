<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\CreateExpense;
use App\Models\Allocation;
use App\Models\Unit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

final class ExpenseCreationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 10, 9)->startOfDay());
        Http::preventStrayRequests();
    }

    private function payload(string $currency = 'BRL', string $amount = '0.01'): array
    {
        $units = collect([1, 2])->map(fn ($index) => Unit::firstOrCreate(['slug' => "unit-$index"], ['name' => "Unidade $index", 'company' => 'Gridmidia']));

        return [
            'description' => 'Licença de software',
            'supplier' => 'Fornecedor SaaS',
            'date' => '2026-10-03',
            'amount' => $amount,
            'currency' => $currency,
            'allocations' => $units->map(fn ($unit) => ['unit_id' => $unit->id, 'percentage' => '50.00'])->all(),
        ];
    }

    public function test_brl_is_available_immediately_without_a_job_or_http_call(): void
    {
        $expense = app(CreateExpense::class)->handle($this->payload('BRL', '100.01'));
        $this->assertSame('converted', $expense->status);
        $this->assertSame(10001, $expense->brl_amount_minor);
        $this->assertSame([5001, 5000], $expense->allocations->pluck('brl_amount_minor')->all());
        $this->assertDatabaseCount('jobs', 0);
        Http::assertNothingSent();
    }

    #[DataProvider('invalidExpenses')]
    public function test_invalid_input_does_not_persist_any_partial_expense(string $field, mixed $value): void
    {
        $payload = $this->payload();
        data_set($payload, $field, $value);
        try {
            app(CreateExpense::class)->handle($payload);
            $this->fail('Invalid payload was accepted.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('expenses', 0);
            $this->assertDatabaseCount('allocations', 0);
            $this->assertDatabaseCount('jobs', 0);
        }
    }

    public static function invalidExpenses(): array
    {
        return [
            ['amount', '0'], ['amount', '1000000000.00'], ['amount', '12.345'], ['amount', 10.5],
            ['date', '2026-02-30'], ['date', '2026-10-10'], ['description', '   '], ['supplier', ''],
            ['currency', 'EUR'], ['allocations.0.percentage', '49.99'], ['allocations.0.percentage', '50.001'],
            ['allocations.0.unit_id', 99999], ['allocations.1.unit_id', 1], ['allocations', []],
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
