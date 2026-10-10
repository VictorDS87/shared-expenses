<?php

declare(strict_types=1);

use App\Actions\CreateExpense;
use App\Domain\Expenses\AllocationCalculator;
use App\Jobs\ConvertExpenseCurrency;
use App\Models\Expense;
use App\Models\Unit;
use App\Services\Exchange\ExchangeRate;
use App\Services\Exchange\ExchangeRateProvider;
use App\Services\UnitReport;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\Process;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (config('database.default') !== 'mysql' || ! str_ends_with(config('database.connections.mysql.database'), '_concurrency_test')) {
    throw new RuntimeException('Use a dedicated MySQL database ending in _concurrency_test.');
}

function check(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function barrier(string $group, string $worker): void
{
    DB::table('test_barriers')->insert(['name' => $group.'-'.$worker]);
    $deadline = microtime(true) + 15;
    while (DB::table('test_barriers')->where('name', 'like', $group.'-%')->count() < 2) {
        check(microtime(true) < $deadline, 'Barrier timed out');
        usleep(10000);
    }
}

$mode = $argv[1] ?? 'parent';
if ($mode === 'convert') {
    $id = (int) $argv[2];
    $worker = $argv[3];
    Expense::updated(function (Expense $expense) {
        if ($expense->wasChanged('status') && $expense->status === 'converted') {
            DB::table('test_barriers')->insert(['name' => 'transition-'.$expense->id]);
        }
    });
    $provider = new class($worker) implements ExchangeRateProvider
    {
        public function __construct(private string $worker) {}

        public function usdToBrl(string $date): ExchangeRate
        {
            // Both processes passed the initial pending check before either commits.
            barrier('conversion', $this->worker);
            usleep(300000);

            return new ExchangeRate('5.00000000', $date, 'concurrency-test');
        }
    };
    (new ConvertExpenseCurrency($id))->handle($provider, new AllocationCalculator);
    exit(0);
}
$app->make(Kernel::class)->call('migrate:fresh', ['--force' => true]);
Schema::create('test_barriers', function (Blueprint $table) {
    $table->string('name')->primary();
});
$unit = Unit::create(['name' => 'Concurrent', 'company' => 'Test', 'slug' => 'test-unit']);
$expense = app(CreateExpense::class)->handle([
    'description' => 'Concurrent conversion', 'supplier' => 'Test', 'date' => '2026-01-01',
    'amount' => '1.00', 'currency' => 'USD', 'allocations' => [['unit_id' => $unit->id, 'percentage' => '100']],
]);
$workers = [];
foreach (['a', 'b'] as $worker) {
    $process = new Process([PHP_BINARY, __FILE__, 'convert', (string) $expense->id, $worker], base_path());
    $process->setTimeout(30)->start();
    $workers[] = $process;
}
$snapshots = 0;
do {
    $report = app(UnitReport::class)->build('2026-01-01', '2026-01-01');
    check($report['total_minor'] === $report['expense_count'] * 500, 'Report crossed snapshots');
    check($report['expense_count'] + $report['pending_count'] === 1, 'Report lost an expense');
    $snapshots++;
    usleep(10000);
} while ($workers[0]->isRunning() || $workers[1]->isRunning());
foreach ($workers as $worker) {
    check($worker->wait() === 0, $worker->getErrorOutput());
}
check(DB::table('test_barriers')->where('name', 'transition-'.$expense->id)->count() === 1, 'Duplicate conversion transition');
check($expense->refresh()->brl_amount_minor === 500, 'Wrong conversion total');
check((int) $expense->allocations()->sum('brl_amount_minor') === 500, 'Allocation mismatch');
echo "PASS: two conversion processes, one transition, {$snapshots} consistent report snapshots.\n";
