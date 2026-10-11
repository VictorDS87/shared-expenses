<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('import_batches', function (Blueprint $table) {
            $table->string('import_key', 64)->nullable()->unique();
            $table->longText('source_content')->nullable();
            $table->string('status')->default('completed')->index();
            $table->unsignedInteger('expected_rows')->default(0);
            $table->string('processing_error')->nullable();
            $table->timestamp('completed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('import_batches', function (Blueprint $table) {
            $table->dropUnique(['import_key']);
            $table->dropColumn(['import_key', 'source_content', 'status', 'expected_rows', 'processing_error', 'completed_at']);
        });
    }
};
