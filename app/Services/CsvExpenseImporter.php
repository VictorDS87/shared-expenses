<?php

namespace App\Services;

use App\Actions\CreateExpense;
use App\Models\ImportBatch;
use App\Models\Unit;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

class CsvExpenseImporter
{
    public const HEADER = ['data', 'descricao', 'fornecedor', 'valor', 'moeda', 'rateio'];

    public const MAX_ROWS = 1000;

    public function __construct(private readonly CreateExpense $createExpense) {}

    public function import(UploadedFile $file, ?int $userId = null): ImportBatch
    {
        $stream = fopen($file->getRealPath(), 'rb');
        if ($stream === false) {
            throw ValidationException::withMessages(['file' => 'Não foi possível ler o arquivo.']);
        }

        try {
            // Remove the BOM before parsing, including when the first cell is quoted.
            if (fread($stream, 3) !== "\xEF\xBB\xBF") {
                rewind($stream);
            }
            $line = 1;
            $header = $this->readRecord($stream, $line);
            if ($header !== self::HEADER) {
                throw ValidationException::withMessages(['file' => 'Cabeçalho inválido. Use: '.implode(';', self::HEADER)]);
            }

            // Preflight before any mutation. Excess rows must never silently disappear.
            $records = [];
            while (true) {
                $recordLine = $line;
                $row = $this->readRecord($stream, $line);
                if ($row === false) {
                    break;
                }
                if ($row === [null]) {
                    continue;
                }
                $records[] = ['line' => $recordLine, 'row' => $row];
                if (count($records) > self::MAX_ROWS) {
                    throw ValidationException::withMessages(['file' => 'O arquivo pode conter no máximo 1.000 registros. Divida-o em arquivos menores.']);
                }
            }

            if ($records === []) {
                throw ValidationException::withMessages(['file' => 'O arquivo precisa conter pelo menos um registro.']);
            }

            // The same bytes from the same operator identify one import, even after a lost response.
            $batch = ImportBatch::query()->firstOrCreate(['import_key' => hash('sha256', ($userId ?? 'anonymous').':'.file_get_contents($file->getRealPath()))], [
                'source_content' => base64_encode(file_get_contents($file->getRealPath())),
                'status' => 'processing', 'expected_rows' => count($records),
                'user_id' => $userId, 'original_name' => mb_substr($file->getClientOriginalName(), 0, 255),
                'errors' => [], 'total_rows' => 0, 'imported_rows' => 0, 'failed_rows' => 0,
            ]);
            if ($batch->status === 'completed') {
                // Record this upload separately; previously accepted rows are not new imports.
                return ImportBatch::create([
                    'user_id' => $userId,
                    'original_name' => mb_substr($file->getClientOriginalName(), 0, 255),
                    'original_import_id' => $batch->id,
                    'status' => 'completed', 'completed_at' => now(),
                    'expected_rows' => $batch->expected_rows ?: $batch->total_rows,
                    'total_rows' => $batch->total_rows,
                    'imported_rows' => 0, 'ignored_rows' => $batch->imported_rows,
                    'failed_rows' => $batch->failed_rows, 'errors' => $batch->errors,
                    'successful_rows' => [],
                ]);
            }
            ImportBatch::whereKey($batch->id)->where('status', '!=', 'completed')->update(['status' => 'processing', 'processing_error' => null]);
            try {
                $units = Unit::query()->pluck('id', 'slug');
                foreach ($records as $index => ['line' => $line, 'row' => $row]) {
                    // Each row commits independently, including its report entry and queued conversion.
                    DB::transaction(function () use ($batch, $row, $line, $units, $index) {
                        $batch = ImportBatch::query()->lockForUpdate()->findOrFail($batch->id);
                        if ($batch->total_rows > $index) {
                            return;
                        }
                        $batch->total_rows++;
                        try {
                            if (count($row) !== count(self::HEADER)) {
                                throw ValidationException::withMessages(['row' => 'A linha precisa ter exatamente 6 colunas.']);
                            }
                            if (! mb_check_encoding(implode('', $row), 'UTF-8')) {
                                throw ValidationException::withMessages(['row' => 'Use codificação UTF-8.']);
                            }
                            $data = array_combine(self::HEADER, $row);
                            $allocations = [];
                            foreach (explode('|', $data['rateio']) as $part) {
                                $pair = explode(':', $part);
                                if (count($pair) !== 2 || ! isset($units[trim($pair[0])])) {
                                    throw ValidationException::withMessages(['rateio' => 'Unidade inexistente ou rateio inválido: '.mb_substr($part, 0, 100)]);
                                }
                                $allocations[] = ['unit_id' => $units[trim($pair[0])], 'percentage' => trim($pair[1])];
                            }
                            $expense = $this->createExpense->handle([
                                'description' => $data['descricao'], 'supplier' => $data['fornecedor'],
                                'date' => $data['data'], 'amount' => $data['valor'],
                                'currency' => $data['moeda'], 'allocations' => $allocations,
                            ]);
                            $batch->successful_rows = [...($batch->successful_rows ?? []), ['line' => $line, 'expense_id' => $expense->id]];
                            $batch->imported_rows++;
                        } catch (ValidationException $exception) {
                            $batch->failed_rows++;
                            $batch->errors = [...$batch->errors, ['line' => $line, 'messages' => array_values(array_merge(...array_values($exception->errors())))]];
                        }
                        $batch->save();
                    });
                }

                ImportBatch::whereKey($batch->id)->update(['status' => 'completed', 'processing_error' => null, 'completed_at' => now()]);
            } catch (Throwable $exception) {
                ImportBatch::whereKey($batch->id)->where('status', '!=', 'completed')->update(['status' => 'interrupted', 'processing_error' => 'Processamento interrompido. Retome este arquivo para continuar das linhas já confirmadas.']);
                Log::error('import.interrupted', ['import_id' => $batch->id, 'exception' => $exception]);
                throw $exception;
            }

            return $batch->refresh();
        } finally {
            fclose($stream);
        }
    }

    public function resume(ImportBatch $batch): ImportBatch
    {
        if ($batch->status === 'completed' || $batch->source_content === null) {
            return $batch;
        }
        $path = tempnam(sys_get_temp_dir(), 'grid-import-');
        try {
            file_put_contents($path, base64_decode($batch->source_content, true));

            return $this->import(new UploadedFile($path, $batch->original_name, null, null, true), $batch->user_id);
        } finally {
            unlink($path);
        }
    }

    /** Track physical line numbers, including quoted cells containing newlines. */
    private function readRecord($stream, int &$line): array|false
    {
        $start = ftell($stream);
        $row = fgetcsv($stream, 0, ';', '"', '');
        if ($row === false) {
            return false;
        }
        $end = ftell($stream);
        fseek($stream, $start);
        $raw = fread($stream, $end - $start);
        $line += max(1, preg_match_all('/\r\n|\r|\n/', $raw));

        return $row;
    }
}
