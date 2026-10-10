<?php

namespace Tests\Feature;

use App\Actions\CreateExpense;
use App\Models\Unit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FinanceStatusTest extends TestCase
{
    use RefreshDatabase;

    public function test_status_distinguishes_healthy_pending_stale_and_failed_conversions(): void
    {
        $this->artisan('finance:status', ['--json' => true])->assertSuccessful();
        $unit = Unit::create(['name' => 'Test', 'company' => 'Test', 'slug' => 'test']);
        $expense = app(CreateExpense::class)->handle([
            'description' => 'USD expense', 'supplier' => 'Test', 'date' => now()->toDateString(),
            'amount' => '1.00', 'currency' => 'USD', 'allocations' => [['unit_id' => $unit->id, 'percentage' => '100']],
        ]);
        $this->artisan('finance:status', ['--json' => true])->expectsOutputToContain('"pending_conversions":1')->assertSuccessful();
        $this->travel(11)->minutes();
        $this->artisan('finance:status', ['--json' => true])->expectsOutputToContain('"stale_conversions":1')->assertFailed();
        $expense->update(['status' => 'failed']);
        $this->artisan('finance:status', ['--json' => true])->expectsOutputToContain('"failed_conversions":1')->assertFailed();
        $this->artisan('finance:status', ['--stale-minutes' => '0'])->assertExitCode(2);
    }
}
