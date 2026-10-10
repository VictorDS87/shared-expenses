<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\CreateExpense;
use App\Models\Expense;
use App\Models\Unit;
use App\Models\User;
use App\Services\UnitReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class ReportTest extends TestCase
{
    use RefreshDatabase;

    private array $units;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 10, 31)->startOfDay());
        Http::preventStrayRequests();
        $user = User::factory()->create();
        $this->withToken($user->createToken('Relatórios', ['*'], now()->addHours(8))->plainTextToken);
        $this->units = [];
        foreach (['Alpha', 'Beta', 'Gamma', 'Sem despesas'] as $index => $name) {
            $this->units[] = Unit::create(['name' => $name, 'company' => 'Grupo Gridmidia', 'slug' => 'unidade-'.($index + 1)]);
        }
    }

    private function expense(string $date, string $amount, array $weights, string $currency = 'BRL'): Expense
    {
        $allocations = [];
        foreach ($weights as $index => $percentage) {
            $allocations[] = ['unit_id' => $this->units[$index]->id, 'percentage' => $percentage];
        }

        return app(CreateExpense::class)->handle([
            'description' => 'Despesa compartilhada', 'supplier' => 'Fornecedor',
            'date' => $date, 'amount' => $amount, 'currency' => $currency, 'allocations' => $allocations,
        ]);
    }

    public function test_report_preserves_cents_and_uses_inclusive_dates(): void
    {
        $this->expense('2026-10-01', '0.01', [0 => '50', 1 => '50']);
        $this->expense('2026-10-09', '0.07', [0 => '33.33', 1 => '33.33', 2 => '33.34']);
        $this->expense('2026-10-09', '5.05', [1 => '25', 2 => '75']);
        $this->expense('2026-09-30', '999999999.99', [0 => '100']);
        $this->expense('2026-10-10', '999999999.99', [0 => '100']);

        $report = app(UnitReport::class)->build('2026-10-01', '2026-10-09');
        $this->assertSame(513, $report['total_minor']);
        $this->assertSame(3, $report['expense_count']);
        $this->assertSame(0, $report['pending_count']);
        $this->assertSame(0, $report['failed_count']);
        $this->assertSame([3, 128, 382, 0], array_column($report['units'], 'total_minor'));
        $this->assertSame([2, 3, 2, 0], array_column($report['units'], 'expense_count'));
        $this->assertSame($report['total_minor'], array_sum(array_column($report['units'], 'total_minor')));

        $this->getJson('/api/v1/reports/units?from=2026-10-01&to=2026-10-09')->assertOk()
            ->assertJsonPath('data.total_minor', 513)->assertJsonPath('data.expense_count', 3)
            ->assertJsonPath('data.pending_count', 0)->assertJsonPath('data.failed_count', 0)
            ->assertJsonPath('data.units.3.total_minor', 0)->assertJsonCount(4, 'data.units');
        $singleDay = app(UnitReport::class)->build('2026-10-09', '2026-10-09');
        $this->assertSame(512, $singleDay['total_minor']);
        $this->assertSame(2, $singleDay['expense_count']);
    }

    public function test_report_uses_one_statement_for_all_aggregates(): void
    {
        $this->expense('2026-10-01', '10.01', [0 => '100']);
        DB::enableQueryLog();
        DB::flushQueryLog();
        $report = app(UnitReport::class)->build('2026-10-01', '2026-10-01');
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertCount(1, $queries);
        $this->assertSame(1001, $report['total_minor']);
        $this->assertSame(1, $report['expense_count']);
    }

    public function test_an_empty_period_returns_zero_for_every_registered_unit(): void
    {
        $this->expense('2026-10-01', '999.99', [0 => '100']);
        $this->getJson('/api/v1/reports/units?from=2026-09-01&to=2026-09-30')->assertOk()
            ->assertJsonPath('data.total_minor', 0)->assertJsonPath('data.expense_count', 0)
            ->assertJsonPath('data.pending_count', 0)->assertJsonPath('data.failed_count', 0)
            ->assertJsonCount(4, 'data.units');
    }

    public function test_report_remains_exact_for_large_supported_original_amounts(): void
    {
        $this->expense('2026-10-01', '999999999.99', [0 => '50', 1 => '50']);
        $this->expense('2026-10-01', '999999999.99', [0 => '50', 1 => '50']);
        $report = app(UnitReport::class)->build('2026-10-01', '2026-10-01');
        $this->assertSame(199999999998, $report['total_minor']);
        $this->assertSame([100000000000, 99999999998, 0, 0], array_column($report['units'], 'total_minor'));
    }

    #[DataProvider('invalidPeriods')]
    public function test_invalid_api_periods_return_validation_errors(string $query, string $field): void
    {
        $this->getJson('/api/v1/reports/units'.$query)->assertUnprocessable()->assertJsonValidationErrors($field);
    }

    public static function invalidPeriods(): array
    {
        return [
            ['', 'from'],
            ['?from=2026-10-01', 'to'],
            ['?to=2026-10-09', 'from'],
            ['?from=2026-02-30&to=2026-10-09', 'from'],
            ['?from=2026-10-09&to=2026-10-01', 'to'],
        ];
    }
}
