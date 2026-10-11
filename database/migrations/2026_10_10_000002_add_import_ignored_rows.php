<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('import_batches', function (Blueprint $table) {
            $table->unsignedInteger('ignored_rows')->default(0);
            $table->foreignId('original_import_id')->nullable()->constrained('import_batches')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('import_batches', function (Blueprint $table) {
            $table->dropConstrainedForeignId('original_import_id');
            $table->dropColumn('ignored_rows');
        });
    }
};
