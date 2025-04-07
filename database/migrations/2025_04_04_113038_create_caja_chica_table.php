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
            $table->id('ID');
            $table->date('FECHA')->useCurrent();
            $table->string('CODIGO')->nullable()->default('CAJA CHICA xx xx');
            $table->string('DESCRIPCION')->nullable()->default('—');
            $table->decimal('SALDO', 10, 2)->default(0);
            $table->string('CENTRO_COSTO')->nullable();
            $table->string('CUENTA')->nullable()->default('—');
            $table->string('NOMBRE_DE_CUENTA')->nullable();
            $table->enum('PROVEEDOR', ['CAJA CHICA', 'DESCUENTOS'])->default('CAJA CHICA');
            $table->string('EMPRESA')->default('SERSUPPORT');
            $table->string('PROYECTO')->nullable();
            $table->string('I_E')->default('EGRESO');
            $table->date('MES_SERVICIO')->nullable();
            $table->string('TIPO')->nullable();
            $table->string('ESTADO')->nullable()->default('PENDING');
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
