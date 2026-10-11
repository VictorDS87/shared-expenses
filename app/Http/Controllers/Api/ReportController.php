<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\UnitReport;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    public function __invoke(Request $request, UnitReport $report)
    {
        $data = $request->validate(['from' => ['required', 'date_format:Y-m-d'], 'to' => ['required', 'date_format:Y-m-d', ...(is_string($request->input('from')) ? ['after_or_equal:from'] : [])]]);

        return response()->json(['data' => $report->build($data['from'], $data['to'])]);
    }
}
