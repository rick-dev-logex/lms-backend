<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('loans', function (Blueprint $table) {
            $table->id();
            $table->date('loan_date')->default(now());
            $table->enum('type', ['nomina', 'proveedor'])->default('nomina');
            $table->foreignId('account_id')->constrained('accounts');
            $table->decimal('amount', 10, 2);
            $table->string('project');
            $table->string('file_path');
            $table->string('note');
            $table->tinyInteger('installments');
            $table->string('responsible_id')->nullable();
            $table->string('vehicle_id')->nullable();
            $table->enum('status', ['pending', 'paid', 'rejected', 'review'])->default('pending');
            $table->timestamps();

            // Índices para optimizar búsquedas
            $table->index('project');
            $table->index('responsible_id');
            $table->index('vehicle_id');
            $table->index(['status', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('loans');
    }
};
