<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Expenses\AllocationCalculator;
use App\Domain\Money\DecimalMoney;
use App\Models\Expense;
use App\Services\Exchange\ExchangeRateProvider;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

final class ConvertExpenseCurrency implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 20;

    public bool $failOnTimeout = true;

    public function __construct(public readonly int $expenseId, public readonly int $conversionVersion = 1) {}

    public function backoff(): array
    {
        return [10, 60, 180];
    }

    public function handle(ExchangeRateProvider $provider, AllocationCalculator $calculator): void
    {
        $expense = Expense::find($this->expenseId);
        if (! $expense || $expense->currency !== 'USD' || $expense->status !== 'pending' || $expense->conversion_version !== $this->conversionVersion) {
            return;
        }

        try {
            // Network calls do not hold database locks. A duplicate worker may fetch
            // the same quote, but only the first can perform the state transition.
            $rate = $provider->usdToBrl($expense->expense_date->format('Y-m-d'));

            $converted = DB::transaction(function () use ($rate, $calculator): bool {
                $expense = Expense::query()->lockForUpdate()->find($this->expenseId);
                if (! $expense || $expense->status !== 'pending' || $expense->conversion_version !== $this->conversionVersion) {
                    return false;
                }
                if ($rate->date > $expense->expense_date->format('Y-m-d')) {
                    throw new \UnexpectedValueException('Cotação posterior à data da despesa.');
                }

                $total = DecimalMoney::convert($expense->amount_minor, $rate->rate);
                $allocations = $expense->allocations()->get();
                $weights = $allocations->pluck('percentage_bps', 'unit_id')->all();
                $amounts = $calculator->allocate($total, $weights);

                foreach ($allocations as $allocation) {
                    $allocation->update(['brl_amount_minor' => $amounts[$allocation->unit_id]]);
                }

                $expense->update([
                    'brl_amount_minor' => $total,
                    'exchange_rate' => $rate->rate,
                    'exchange_rate_date' => $rate->date,
                    'exchange_rate_source' => $rate->source,
                    'status' => 'converted',
                    'conversion_error' => null,
                    'converted_at' => now(),
                ]);

                return true;
            });
            Log::info($converted ? 'conversion.completed' : 'conversion.skipped', $this->context());
        } catch (Throwable $exception) {
            Log::warning('conversion.attempt_failed', [...$this->context(), 'exception_class' => $exception::class, 'exception' => $exception]);
            Expense::whereKey($this->expenseId)->where('status', 'pending')->where('conversion_version', $this->conversionVersion)->update([
                'conversion_error' => 'Não foi possível consultar uma cotação válida. Uma nova tentativa automática será realizada.',
            ]);

            throw $exception;
        }
    }

    public function failed(?Throwable $exception): void
    {
        // Conditional update cannot overwrite a conversion completed by another worker.
        $changed = Expense::whereKey($this->expenseId)->where('status', 'pending')->where('conversion_version', $this->conversionVersion)->update([
            'status' => 'failed',
            'conversion_error' => 'Não foi possível concluir a conversão após as tentativas automáticas. Tente reprocessar a despesa.',
        ]);
        Log::log($changed ? 'error' : 'info', $changed ? 'conversion.exhausted' : 'conversion.stale_failure_ignored', [...$this->context(), 'exception_class' => $exception ? $exception::class : null, 'exception' => $exception]);
    }

    private function context(): array
    {
        return ['expense_id' => $this->expenseId, 'conversion_version' => $this->conversionVersion,
            'attempt' => $this->job ? $this->attempts() : 1, 'queue_job_id' => $this->job?->getJobId()];
    }
}
