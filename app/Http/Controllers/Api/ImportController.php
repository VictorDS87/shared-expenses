<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ImportBatch;
use App\Services\CsvExpenseImporter;
use Illuminate\Http\Request;

class ImportController extends Controller
{
    public function store(Request $request, CsvExpenseImporter $importer)
    {
        $request->validate(['file' => ['required', 'file', 'max:2048', 'extensions:csv,txt']]);

        return response()->json(['data' => $importer->import($request->file('file'), $request->user()->id)], 201);
    }

    public function resume(ImportBatch $import, CsvExpenseImporter $importer)
    {
        $batch = $importer->resume($import);

        return response()->json(['data' => $batch]);
    }

    public function show(ImportBatch $import)
    {
        return response()->json(['data' => $import]);
    }
}
