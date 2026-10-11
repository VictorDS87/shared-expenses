<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('import_batches', function (Blueprint $table) {
            $table->json('successful_rows')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('import_batches', fn (Blueprint $table) => $table->dropColumn('successful_rows'));
    }
};
