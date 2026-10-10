<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\CreateExpense;
use App\Actions\RetryExpenseConversion;
use App\Domain\Expenses\AllocationCalculator;
use App\Jobs\ConvertExpenseCurrency;
use App\Models\Expense;
use App\Models\Unit;
use App\Services\Exchange\ExchangeRate;
use App\Services\Exchange\ExchangeRateProvider;
use App\Services\Exchange\FrankfurterExchangeRateProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;
use Throwable;

final class ExpenseConversionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 10, 9)->startOfDay());
        Http::preventStrayRequests();
        config(['queue.connections.database.connection' => null]);
    }

    public function test_failure_logs_identify_expense_version_and_distinguish_stale_callbacks(): void
    {
        Log::spy();
        $expense = app(CreateExpense::class)->handle($this->payload());
        $job = new ConvertExpenseCurrency($expense->id);
        $job->failed(new RuntimeException('Provider unavailable'));
        Log::shouldHaveReceived('log')->with('error', 'conversion.exhausted', Mockery::on(fn ($context) => $context['expense_id'] === $expense->id && $context['conversion_version'] === 1 && $context['attempt'] === 1))->once();
        $job->failed(new RuntimeException('Late duplicate failure'));
        Log::shouldHaveReceived('log')->with('info', 'conversion.stale_failure_ignored', Mockery::type('array'))->once();
    }

    private function payload(string $currency = 'USD', string $amount = '0.01'): array
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

    private function runConversion(Expense $expense): void
    {
        (new ConvertExpenseCurrency($expense->id, $expense->conversion_version ?? 1))->handle(new FrankfurterExchangeRateProvider, new AllocationCalculator);
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

    public function test_usd_creation_persists_pending_expense_and_job_without_http(): void
    {
        $expense = app(CreateExpense::class)->handle($this->payload());
        $this->assertSame('pending', $expense->status);
        $this->assertNull($expense->brl_amount_minor);
        $this->assertSame([null, null], $expense->allocations->pluck('brl_amount_minor')->all());
        $this->assertDatabaseCount('jobs', 1);
        Http::assertNothingSent();
    }

    public function test_queue_row_and_expense_rollback_together(): void
    {
        $payload = $this->payload();
        try {
            DB::transaction(function () use ($payload): void {
                app(CreateExpense::class)->handle($payload);
                $this->assertDatabaseCount('jobs', 1);
                throw new RuntimeException('Abort transaction');
            });
        } catch (RuntimeException $exception) {
            $this->assertSame('Abort transaction', $exception->getMessage());
        }
        $this->assertDatabaseCount('expenses', 0);
        $this->assertDatabaseCount('allocations', 0);
        $this->assertDatabaseCount('jobs', 0);
    }

    public function test_conversion_records_historical_rate_and_allocates_converted_total_once(): void
    {
        Http::fake(['*' => Http::response('{"date":"2026-10-02","base":"USD","quote":"BRL","rate":5.5}', 200, ['Content-Type' => 'application/json'])]);
        $expense = app(CreateExpense::class)->handle($this->payload());
        $this->runConversion($expense);
        $expense->refresh()->load('allocations');

        $this->assertSame('converted', $expense->status);
        $this->assertSame(6, $expense->brl_amount_minor);
        // Converting already rounded original allocations would incorrectly yield [6, 0].
        $this->assertSame([3, 3], $expense->allocations->pluck('brl_amount_minor')->all());
        $this->assertSame('5.50000000', $expense->exchange_rate);
        $this->assertSame('2026-10-02', $expense->exchange_rate_date->format('Y-m-d'));
        $this->assertSame('Frankfurter / ECB', $expense->exchange_rate_source);
        $this->assertNotNull($expense->converted_at);
        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/v2/providers/ecb/rate/USD/BRL') && $request['date'] === '2026-10-03');
    }

    public function test_redelivered_job_is_idempotent_and_does_not_request_another_rate(): void
    {
        Http::fake(['*' => Http::response(['date' => '2026-10-02', 'base' => 'USD', 'quote' => 'BRL', 'rate' => 5])]);
        $expense = app(CreateExpense::class)->handle($this->payload());
        $this->runConversion($expense);
        $this->runConversion($expense);
        Http::assertSentCount(1);
        $this->assertDatabaseCount('allocations', 2);
        $this->assertSame(5, $expense->refresh()->brl_amount_minor);
    }

    public function test_a_duplicate_worker_that_finishes_first_cannot_be_overwritten(): void
    {
        $expense = app(CreateExpense::class)->handle($this->payload());
        $provider = Mockery::mock(ExchangeRateProvider::class);
        $provider->shouldReceive('usdToBrl')->once()->andReturnUsing(function () use ($expense) {
            $expense->update(['status' => 'converted', 'brl_amount_minor' => 777]);

            return new ExchangeRate('5.00', '2026-10-02', 'Frankfurter / ECB');
        });
        (new ConvertExpenseCurrency($expense->id))->handle($provider, new AllocationCalculator);
        $this->assertSame(777, $expense->refresh()->brl_amount_minor);
    }

    public function test_failed_rate_lookup_keeps_original_amount_and_never_invents_brl_values(): void
    {
        Http::fake(['*' => Http::response(['message' => 'Unavailable'], 503)]);
        $expense = app(CreateExpense::class)->handle($this->payload());
        try {
            $this->runConversion($expense);
            $this->fail('Provider failure must be rethrown so the queue retries it.');
        } catch (RequestException) {
        }
        $expense->refresh()->load('allocations');
        $this->assertSame('pending', $expense->status);
        $this->assertSame(1, $expense->amount_minor);
        $this->assertNull($expense->brl_amount_minor);
        $this->assertNotNull($expense->conversion_error);
        $this->assertSame([null, null], $expense->allocations->pluck('brl_amount_minor')->all());

        (new ConvertExpenseCurrency($expense->id))->failed(new RuntimeException);
        $this->assertSame('failed', $expense->refresh()->status);
    }

    public function test_failed_conversion_can_be_retried_and_stale_callbacks_are_ignored(): void
    {
        $expense = app(CreateExpense::class)->handle($this->payload());
        $oldJob = new ConvertExpenseCurrency($expense->id);
        $oldJob->failed(new RuntimeException);
        $retried = app(RetryExpenseConversion::class)->handle($expense);
        $this->assertSame('pending', $retried->status);
        $this->assertSame(2, $retried->conversion_version);
        $this->assertNull($retried->conversion_error);
        $this->assertDatabaseCount('jobs', 2);

        $oldJob->failed(new RuntimeException);
        $oldJob->handle(new FrankfurterExchangeRateProvider, new AllocationCalculator);
        $this->assertSame('pending', $expense->refresh()->status);
        Http::assertNothingSent();

        Http::fake(['*' => Http::response(['date' => '2026-10-02', 'base' => 'USD', 'quote' => 'BRL', 'rate' => 5])]);
        $this->runConversion($retried);
        $this->assertSame('converted', $expense->refresh()->status);
    }

    public function test_pending_expense_cannot_enqueue_duplicate_manual_retries(): void
    {
        $expense = app(CreateExpense::class)->handle($this->payload());
        $this->expectException(ValidationException::class);
        try {
            app(RetryExpenseConversion::class)->handle($expense);
        } finally {
            $this->assertDatabaseCount('jobs', 1);
        }
    }

    #[DataProvider('invalidRates')]
    public function test_invalid_provider_responses_do_not_mark_expense_converted(array|string $response): void
    {
        Http::fake(['*' => Http::response($response)]);
        $expense = app(CreateExpense::class)->handle($this->payload());
        $caught = false;
        try {
            $this->runConversion($expense);
        } catch (Throwable) {
            $caught = true;
        }
        $this->assertTrue($caught);
        $this->assertNull($expense->refresh()->brl_amount_minor);
        $this->assertSame('pending', $expense->status);
    }

    public static function invalidRates(): array
    {
        $valid = ['date' => '2026-10-02', 'base' => 'USD', 'quote' => 'BRL', 'rate' => '5.00'];

        return [
            'future date' => [array_replace($valid, ['date' => '2026-10-05'])],
            'impossible date' => [array_replace($valid, ['date' => '2026-02-30'])],
            'wrong currency' => [array_replace($valid, ['base' => 'EUR'])],
            'zero rate' => [array_replace($valid, ['rate' => '0'])],
            'negative rate' => [array_replace($valid, ['rate' => '-5'])],
            'excess precision' => [array_replace($valid, ['rate' => '5.123456789'])],
            'missing rate' => [['date' => '2026-10-02', 'base' => 'USD', 'quote' => 'BRL']],
            'invalid json' => ['not json'],
        ];
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
}
