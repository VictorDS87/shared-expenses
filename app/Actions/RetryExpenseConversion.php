<?php

declare(strict_types=1);

namespace App\Actions;

use App\Jobs\ConvertExpenseCurrency;
use App\Models\Expense;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class RetryExpenseConversion
{
    public function handle(Expense $expense): Expense
    {
        return DB::transaction(function () use ($expense): Expense {
            $locked = Expense::query()->lockForUpdate()->findOrFail($expense->id);
            if ($locked->currency !== 'USD' || $locked->status !== 'failed') {
                throw ValidationException::withMessages(['expense' => 'Somente conversões com falha podem ser reprocessadas.']);
            }
            $locked->update([
                'status' => 'pending',
                'conversion_error' => null,
                'conversion_version' => $locked->conversion_version + 1,
            ]);
            Bus::dispatch((new ConvertExpenseCurrency($locked->id, $locked->conversion_version))->onConnection('database')->beforeCommit());

            return $locked->load('allocations.unit');
        });
    }
}
