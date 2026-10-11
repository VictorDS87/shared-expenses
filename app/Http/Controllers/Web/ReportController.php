<?php

namespace App\Http\Controllers\Web;

use App\Domain\Money\DecimalMoney;
use App\Http\Controllers\Controller;
use App\Services\UnitReport;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    public function index(Request $request, UnitReport $reports)
    {
        [$from, $to] = $this->period($request);

        return view('reports.index', ['report' => $reports->build($from, $to)]);
    }

    public function export(Request $request, UnitReport $reports)
    {
        [$from, $to] = $this->period($request);
        $report = $reports->build($from, $to);

        return response()->streamDownload(function () use ($report) {
            $output = fopen('php://output', 'w');
            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, ['Unidade', 'Empresa', 'Identificador', 'Despesas', 'Total BRL', 'De', 'Até'], ';', '"', '');
            foreach ($report['units'] as $unit) {
                $safe = fn ($value) => preg_match('/^[=+@\-\t\r]/u', (string) $value) ? "'".$value : $value;
                fputcsv($output, [$safe(data_get($unit, 'name')), $safe(data_get($unit, 'company')), $safe(data_get($unit, 'slug')), data_get($unit, 'expense_count'), str_replace('.', ',', DecimalMoney::format((int) data_get($unit, 'total_minor'))), $report['from'], $report['to']], ';', '"', '');
            }
            fclose($output);
        }, "relatorio-unidades-{$from}-{$to}.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function period(Request $request): array
    {
        $request->merge(['from' => $request->input('from') ?: now()->startOfMonth()->toDateString(), 'to' => $request->input('to') ?: now()->endOfMonth()->toDateString()]);
        $validated = $request->validate(['from' => ['required', 'date_format:Y-m-d'], 'to' => ['required', 'date_format:Y-m-d', ...(is_string($request->input('from')) ? ['after_or_equal:from'] : [])]]);

        return [$validated['from'], $validated['to']];
    }
}
