<?php

namespace App\Http\Controllers\Web;

use App\Actions\CreateExpense;
use App\Http\Controllers\Controller;
use App\Models\Expense;
use App\Models\Unit;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ExpenseController extends Controller
{
    public function index(Request $request)
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:150'],
            'status' => ['nullable', Rule::in(['converted'])],
            'currency' => ['nullable', Rule::in(['BRL'])],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', ...($request->filled('from') ? ['after_or_equal:from'] : [])],
        ]);
        $expenses = Expense::query()->with('allocations.unit')
            ->when($filters['search'] ?? null, fn ($query, $value) => $query->where(fn ($query) => $query->where('description', 'like', '%'.$value.'%')->orWhere('supplier', 'like', '%'.$value.'%')))
            ->when($filters['status'] ?? null, fn ($query, $value) => $query->where('status', $value))
            ->when($filters['currency'] ?? null, fn ($query, $value) => $query->where('currency', $value))
            ->when($filters['from'] ?? null, fn ($query, $value) => $query->whereDate('expense_date', '>=', $value))
            ->when($filters['to'] ?? null, fn ($query, $value) => $query->whereDate('expense_date', '<=', $value))
            ->latest('expense_date')->latest('id')->paginate(12)->withQueryString();

        return view('expenses.index', compact('expenses', 'filters'));
    }

    public function create()
    {
        return view('expenses.create', ['units' => Unit::orderBy('name')->get()]);
    }

    public function store(Request $request, CreateExpense $createExpense)
    {
        $payload = $request->only(['description', 'supplier', 'date', 'currency', 'amount', 'allocations']);
        if (isset($payload['amount']) && is_string($payload['amount'])) {
            $payload['amount'] = str_replace(',', '.', $payload['amount']);
        }
        if (isset($payload['allocations']) && is_array($payload['allocations'])) {
            foreach ($payload['allocations'] as &$allocation) {
                if (is_array($allocation) && isset($allocation['percentage']) && is_string($allocation['percentage'])) {
                    $allocation['percentage'] = str_replace(',', '.', $allocation['percentage']);
                }
            }
            unset($allocation);
        }
        $expense = $createExpense->handle($payload);

        return redirect()->route('expenses.show', $expense)->with('success', 'Despesa registrada e rateada. Os valores já compõem os relatórios.');
    }

    public function show(Expense $expense)
    {
        return view('expenses.show', ['expense' => $expense->load('allocations.unit')]);
    }
}
