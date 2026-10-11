<?php

namespace App\Console\Commands;

use App\Models\Expense;
use App\Models\ImportBatch;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class FinanceStatus extends Command
{
    protected $signature = 'finance:status {--json : Output machine-readable status} {--stale-minutes=10 : Pending age threshold}';

    protected $description = 'Inspect conversions, interrupted imports and failed queue jobs';

    public function handle(): int
    {
        $minutes = filter_var($this->option('stale-minutes'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($minutes === false) {
            $this->error('stale-minutes deve ser um inteiro positivo.');

            return self::INVALID;
        }
        $status = [
            'pending_conversions' => Expense::where('status', 'pending')->count(),
            'stale_conversions' => Expense::where('status', 'pending')->where('updated_at', '<', now()->subMinutes($minutes))->count(),
            'failed_conversions' => Expense::where('status', 'failed')->count(),
            'unfinished_imports' => ImportBatch::where('status', '!=', 'completed')->count(),
            'failed_jobs' => DB::table('failed_jobs')->count(),
        ];
        $this->line(json_encode($status, ($this->option('json') ? 0 : JSON_PRETTY_PRINT) | JSON_THROW_ON_ERROR));

        return ($status['stale_conversions'] + $status['failed_conversions'] + $status['unfinished_imports'] + $status['failed_jobs']) > 0 ? self::FAILURE : self::SUCCESS;
    }
}
