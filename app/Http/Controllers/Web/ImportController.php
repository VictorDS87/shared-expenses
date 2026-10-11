<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Expense;
use App\Models\ImportBatch;
use App\Services\CsvExpenseImporter;
use Illuminate\Http\Request;

class ImportController extends Controller
{
    public function index()
    {
        return view('imports.index', ['imports' => ImportBatch::latest()->paginate(10)]);
    }

    public function store(Request $request, CsvExpenseImporter $importer)
    {
        $request->validate(['file' => ['required', 'file', 'max:2048', 'extensions:csv,txt']]);
        $batch = $importer->import($request->file('file'), $request->user()->id);

        $message = ! $batch->original_import_id
            ? 'Arquivo processado: '.$batch->imported_rows.' despesas cadastradas e '.$batch->failed_rows.' linhas com erro. Confira o resultado abaixo.'
            : 'Este arquivo já foi enviado por você. Nenhuma despesa foi cadastrada neste envio: '.$batch->ignored_rows.' linhas ignoradas e '.$batch->failed_rows.' linhas com erro preservadas da importação #'.$batch->original_import_id.'. Confira o resultado abaixo.';

        return redirect()->route('imports.show', $batch)->with('success', $message);
    }

    public function resume(ImportBatch $import, CsvExpenseImporter $importer)
    {
        $batch = $importer->resume($import);

        return redirect()->route('imports.show', $batch)->with('success', 'Importação retomada com segurança.');
    }

    public function show(ImportBatch $import)
    {
        $expenses = Expense::whereIn('id', array_column($import->successful_rows ?? [], 'expense_id'))->get()->keyBy('id');

        return view('imports.show', compact('import', 'expenses'));
    }
}
