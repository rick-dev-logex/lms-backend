<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_details', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')
                  ->constrained('invoices')
                  ->cascadeOnDelete();

            // Campos del detalle (producto/servicio)
            $table->string('codigo_principal', 100)->nullable();
            $table->string('codigo_auxiliar', 100)->nullable();
            $table->string('descripcion', 191)->nullable();
            $table->integer('cantidad')->default(1);
            $table->decimal('precio_unitario', 10, 2)->default(0.00);
            $table->decimal('descuento', 10, 2)->default(0.00);
            $table->decimal('precio_total_sin_impuesto', 10, 2)->default(0.00);

            // Campos de impuestos por detalle
            $table->string('cod_impuesto', 20)->nullable();
            $table->string('cod_porcentaje', 20)->nullable();
            $table->decimal('tarifa', 10, 2)->nullable();
            $table->decimal('base_imponible_impuestos', 10, 2)->nullable();
            $table->decimal('valor_impuestos', 10, 2)->nullable();

            $table->timestamps();

            $table->index('invoice_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_details');
    }
};
