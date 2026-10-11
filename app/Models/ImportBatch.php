<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ImportBatch extends Model
{
    protected $fillable = ['user_id', 'original_name', 'total_rows', 'imported_rows', 'ignored_rows', 'original_import_id', 'failed_rows', 'errors', 'import_key', 'source_content', 'status', 'expected_rows', 'processing_error', 'completed_at', 'successful_rows'];

    protected $hidden = ['source_content', 'import_key'];

    protected function casts(): array
    {
        return ['successful_rows' => 'array', 'completed_at' => 'datetime', 'expected_rows' => 'integer', 'errors' => 'array', 'total_rows' => 'integer', 'imported_rows' => 'integer', 'ignored_rows' => 'integer', 'original_import_id' => 'integer', 'failed_rows' => 'integer'];
    }
}
