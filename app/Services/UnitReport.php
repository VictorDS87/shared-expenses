<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class UnitReport
{
    public function build(string $from, string $to): array
    {
        $totals = DB::table('allocations')->join('expenses', 'expenses.id', '=', 'allocations.expense_id')
            ->where('expenses.status', 'converted')->whereBetween('expenses.expense_date', [$from, $to])
            ->selectRaw('allocations.unit_id, SUM(allocations.brl_amount_minor) AS total_minor, COUNT(DISTINCT expenses.id) AS expense_count')
            ->groupBy('allocations.unit_id');

        $unitsQuery = DB::table('units')->leftJoinSub($totals, 'totals', 'totals.unit_id', '=', 'units.id')
            ->selectRaw('units.id, units.name, units.company, units.slug, COALESCE(totals.total_minor, 0) AS total_minor, COALESCE(totals.expense_count, 0) AS expense_count, 0 AS pending_count, 0 AS failed_count');
        $counts = DB::table('expenses')->whereBetween('expense_date', [$from, $to])
            ->selectRaw("NULL AS id, NULL AS name, NULL AS company, NULL AS slug, 0 AS total_minor, COALESCE(SUM(CASE WHEN status = 'converted' THEN 1 ELSE 0 END), 0) AS expense_count, COALESCE(SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END), 0) AS pending_count, COALESCE(SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END), 0) AS failed_count");

        // One SQL statement: all aggregates share the same MVCC statement snapshot,
        // including on READ COMMITTED. No reliance on session isolation settings.
        $rows = $unitsQuery->unionAll($counts)->get();
        $summary = $rows->first(fn ($row) => $row->id === null);
        $units = $rows->filter(fn ($row) => $row->id !== null)->sortBy('name')->map(fn ($row) => [
            'id' => (int) $row->id, 'name' => $row->name, 'company' => $row->company, 'slug' => $row->slug,
            'total_minor' => (int) $row->total_minor, 'expense_count' => (int) $row->expense_count,
        ])->values()->all();

        return [
            'from' => $from, 'to' => $to,
            'total_minor' => array_sum(array_column($units, 'total_minor')),
            'expense_count' => (int) $summary->expense_count,
            'pending_count' => (int) $summary->pending_count,
            'failed_count' => (int) $summary->failed_count,
            'units' => $units,
        ];
    }
}
