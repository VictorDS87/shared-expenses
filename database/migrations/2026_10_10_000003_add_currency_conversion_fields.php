<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->decimal('exchange_rate', 16, 8)->nullable();
            $table->date('exchange_rate_date')->nullable();
            $table->string('exchange_rate_source', 120)->nullable();
            $table->string('conversion_error', 500)->nullable();
            $table->unsignedInteger('conversion_version')->default(1);
        });
    }

    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->dropColumn(['exchange_rate', 'exchange_rate_date', 'exchange_rate_source', 'conversion_error', 'conversion_version']);
        });
    }
};
