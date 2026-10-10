<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Expense;
use App\Models\Unit;
use App\Services\UnitReport;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __invoke(Request $request, UnitReport $reports)
    {
        $validated = $request->validate(['month' => ['nullable', 'date_format:Y-m']]);
        $month = CarbonImmutable::parse(($validated['month'] ?? now()->format('Y-m')).'-01');
        $from = $month->startOfMonth()->toDateString();
        $to = $month->endOfMonth()->toDateString();
        $report = $reports->build($from, $to);
        $expenses = Expense::query()->whereBetween('expense_date', [$from, $to]);
        $recentExpenses = (clone $expenses)->with('allocations.unit')->latest('expense_date')->latest('id')->limit(6)->get();
        $dailyTotals = (clone $expenses)->where('status', 'converted')
            ->selectRaw('expense_date, SUM(brl_amount_minor) AS total_minor')->groupBy('expense_date')
            ->get()->mapWithKeys(fn ($item) => [CarbonImmutable::parse($item->expense_date)->day => (int) $item->total_minor]);
        $days = collect(range(1, $month->daysInMonth))->map(fn ($day) => ['day' => $day, 'total' => (int) $dailyTotals->get($day, 0)]);

        return view('dashboard', compact('report', 'recentExpenses', 'month', 'days') + [
            'unitCount' => Unit::count(),
            'expenseCount' => (clone $expenses)->count(),
            'convertedCount' => (clone $expenses)->where('status', 'converted')->count(),
        ]);
    }
}
