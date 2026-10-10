<?php

namespace Tests\Feature;

use App\Actions\CreateExpense;
use App\Actions\RetryExpenseConversion;
use App\Models\Expense;
use App\Models\Unit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class QueueConversionTest extends TestCase
{
    use RefreshDatabase;

    private function enqueue(): Expense
    {
        $unit = Unit::create(['name' => 'Test', 'company' => 'Test', 'slug' => 'test']);

        return app(CreateExpense::class)->handle([
            'description' => 'Queue conversion', 'supplier' => 'Test', 'date' => '2026-01-01',
            'amount' => '1.01', 'currency' => 'USD', 'allocations' => [['unit_id' => $unit->id, 'percentage' => '100']],
        ]);
    }

    private function workOnce(): void
    {
        $this->artisan('queue:work', ['connection' => 'database', '--once' => true, '--sleep' => 0])->assertSuccessful();
    }

    public function test_database_worker_retries_a_transient_failure_and_then_converts(): void
    {
        Http::fake(['*' => Http::sequence()->pushStatus(503)->push(['date' => '2025-12-31', 'base' => 'USD', 'quote' => 'BRL', 'rate' => '5.00'])]);
        $expense = $this->enqueue();
        $this->workOnce();
        $this->assertSame('pending', $expense->refresh()->status);
        $this->assertNull($expense->brl_amount_minor);
        $this->assertDatabaseCount('jobs', 1);
        $this->assertSame(1, (int) DB::table('jobs')->value('attempts'));
        $this->assertSame(now()->timestamp + 10, (int) DB::table('jobs')->value('available_at'));
        $this->travel(11)->seconds();
        $this->workOnce();
        $this->assertSame('converted', $expense->refresh()->status);
        $this->assertSame(505, $expense->brl_amount_minor);
        $this->assertSame(505, (int) $expense->allocations()->sum('brl_amount_minor'));
        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('failed_jobs', 0);
        Http::assertSentCount(2);
    }

    public function test_worker_exhausts_three_attempts_and_manual_retry_starts_a_new_version(): void
    {
        Http::fake(['*' => Http::response([], 503)]);
        $expense = $this->enqueue();
        $this->workOnce();
        $this->travel(11)->seconds();
        $this->workOnce();
        $this->travel(61)->seconds();
        $this->workOnce();
        $this->assertSame('failed', $expense->refresh()->status);
        $this->assertSame(101, $expense->amount_minor);
        $this->assertNull($expense->brl_amount_minor);
        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('failed_jobs', 1);
        Http::assertSentCount(3);
        $retried = app(RetryExpenseConversion::class)->handle($expense);
        $this->assertSame('pending', $retried->status);
        $this->assertSame(2, $retried->conversion_version);
        $this->assertNull($retried->conversion_error);
        $this->assertDatabaseCount('jobs', 1);
    }
}
