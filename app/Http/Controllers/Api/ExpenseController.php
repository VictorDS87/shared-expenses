<?php

namespace App\Http\Controllers\Api;

use App\Actions\CreateExpense;
use App\Actions\RetryExpenseConversion;
use App\Http\Controllers\Controller;
use App\Http\Resources\ExpenseResource;
use App\Models\Expense;
use Illuminate\Http\Request;

class ExpenseController extends Controller
{
    public function index(Request $request)
    {
        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:100'], 'status' => ['nullable', 'in:pending,converted,failed'],
            'currency' => ['nullable', 'in:BRL,USD'], 'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', ...($request->filled('from') && is_string($request->input('from')) ? ['after_or_equal:from'] : [])],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $query = Expense::query()->with('allocations.unit');
        foreach (['status', 'currency'] as $filter) {
            $query->when($data[$filter] ?? null, fn ($q, $value) => $q->where($filter, $value));
        }
        $query->when($data['from'] ?? null, fn ($q, $value) => $q->where('expense_date', '>=', $value))
            ->when($data['to'] ?? null, fn ($q, $value) => $q->where('expense_date', '<=', $value))
            ->when($data['search'] ?? null, fn ($q, $value) => $q->where(fn ($q) => $q->where('description', 'like', '%'.$value.'%')->orWhere('supplier', 'like', '%'.$value.'%')));

        return ExpenseResource::collection($query->orderByDesc('expense_date')->orderByDesc('id')->paginate($data['per_page'] ?? 20)->withQueryString());
    }

    public function store(Request $request, CreateExpense $createExpense)
    {
        return (new ExpenseResource($createExpense->handle($request->all())))->response()->setStatusCode(201);
    }

    public function show(Expense $expense)
    {
        return new ExpenseResource($expense->load('allocations.unit'));
    }

    public function retry(Expense $expense, RetryExpenseConversion $retry)
    {
        return (new ExpenseResource($retry->handle($expense)->load('allocations.unit')))->response()->setStatusCode(202);
    }
}
