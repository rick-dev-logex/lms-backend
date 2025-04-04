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
        Schema::create('caja_chica', function (Blueprint $table) {
            $table->id();
            $table->date('fecha');
            $table->string('codigo');
            $table->string('descripcion');
            $table->decimal('saldo', 10, 2)->default(0);
            $table->string('centro_costo');
            $table->string('cuenta');
            $table->string('nombre_de_cuenta');
            $table->enum('proveedor', ['CAJA CHICA', 'DESCUENTOS'])->default('CAJA CHICA');
            $table->string('empresa')->default('SERSUPPORT');
            $table->string('proyecto');
            $table->string('i_e')->default('EGRESO');
            $table->date('mes_servicio');
            $table->string('tipo');
            $table->string('estado');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('caja_chica');
    }
};
