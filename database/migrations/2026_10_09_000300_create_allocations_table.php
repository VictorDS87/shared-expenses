<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('expense_id')->constrained()->cascadeOnDelete();
            $table->foreignId('unit_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('percentage_bps');
            $table->unsignedBigInteger('amount_minor');
            $table->unsignedBigInteger('brl_amount_minor')->nullable();
            $table->timestamps();
            $table->unique(['expense_id', 'unit_id']);
            $table->index(['unit_id', 'expense_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('allocations');
    }
};
