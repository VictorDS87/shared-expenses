<?php

declare(strict_types=1);

namespace App\Actions;

use App\Domain\Expenses\AllocationCalculator;
use App\Domain\Money\DecimalMoney;
use App\Jobs\ConvertExpenseCurrency;
use App\Models\Expense;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final class CreateExpense
{
    public function __construct(private AllocationCalculator $calculator) {}

    public function handle(array $data): Expense
    {
        foreach (['description', 'supplier'] as $field) {
            if (isset($data[$field]) && is_string($data[$field])) {
                $data[$field] = trim($data[$field]);
            }
        }

        $validator = Validator::make($data, [
            'description' => ['required', 'string', 'max:255'],
            'supplier' => ['required', 'string', 'max:160'],
            'date' => ['required', 'string', 'date_format:Y-m-d', 'before_or_equal:today'],
            'amount' => ['required', 'string', 'regex:/\A(?:0|[1-9]\d{0,8})(?:\.\d{1,2})?\z/'],
            'currency' => ['required', Rule::in(['BRL', 'USD'])],
            'allocations' => ['required', 'array', 'min:1', 'max:100'],
            'allocations.*' => ['required', 'array:unit_id,percentage'],
            'allocations.*.unit_id' => ['required', 'integer', 'distinct', 'exists:units,id'],
            'allocations.*.percentage' => ['required', 'string', 'regex:/\A(?:0|[1-9]\d{0,2})(?:\.\d{1,2})?\z/'],
        ], [
            'amount.regex' => 'Informe um valor de até 999999999.99, com no máximo duas casas decimais.',
            'allocations.*.percentage.regex' => 'Informe um percentual com no máximo duas casas decimais.',
            'allocations.*.unit_id.distinct' => 'Cada unidade pode aparecer apenas uma vez no rateio.',
        ]);

        $validator->after(function ($validator) use ($data): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            if (DecimalMoney::toMinor($data['amount']) <= 0) {
                $validator->errors()->add('amount', 'O valor da despesa deve ser maior que zero.');
            }
            $total = 0;
            foreach ($data['allocations'] as $index => $allocation) {
                $bps = DecimalMoney::toMinor($allocation['percentage']);
                if ($bps <= 0 || $bps > 10000) {
                    $validator->errors()->add("allocations.$index.percentage", 'O percentual deve ser maior que zero e menor ou igual a 100.');
                }
                $total += $bps;
            }
            if ($total !== 10000) {
                $validator->errors()->add('allocations', 'A soma dos percentuais deve ser exatamente 100%.');
            }
        });

        $validated = $validator->validate();
        $amount = DecimalMoney::toMinor($validated['amount']);
        $weights = [];
        foreach ($validated['allocations'] as $allocation) {
            $weights[(int) $allocation['unit_id']] = DecimalMoney::toMinor($allocation['percentage']);
        }
        $allocated = $this->calculator->allocate($amount, $weights);
        $isBrl = $validated['currency'] === 'BRL';

        return DB::transaction(function () use ($validated, $amount, $weights, $allocated, $isBrl): Expense {
            $expense = Expense::create([
                'description' => trim($validated['description']),
                'supplier' => trim($validated['supplier']),
                'expense_date' => $validated['date'],
                'amount_minor' => $amount,
                'currency' => $validated['currency'],
                'brl_amount_minor' => $isBrl ? $amount : null,
                'status' => $isBrl ? 'converted' : 'pending',
                'converted_at' => $isBrl ? now() : null,
            ]);

            foreach ($weights as $unitId => $bps) {
                $expense->allocations()->create([
                    'unit_id' => $unitId,
                    'percentage_bps' => $bps,
                    'amount_minor' => $allocated[$unitId],
                    'brl_amount_minor' => $isBrl ? $allocated[$unitId] : null,
                ]);
            }

            if (! $isBrl) {
                // Same database connection: the queue row rolls back with the expense.
                Bus::dispatch((new ConvertExpenseCurrency($expense->id))->onConnection('database')->beforeCommit());
            }

            return $expense->load('allocations.unit');
        });
    }
}
