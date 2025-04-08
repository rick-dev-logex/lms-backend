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
        Schema::table('caja_chica', function (Blueprint $table) {
            $table->renameColumn('CENTRO_COSTO', 'CENTRO COSTO');
            $table->renameColumn('NOMBRE_DE_CUENTA', 'NOMBRE DE CUENTA');
            $table->renameColumn('MES_SERVICIO', 'MES SERVICIO');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('caja_chica', function (Blueprint $table) {
            $table->renameColumn('CENTRO COSTO', 'CENTRO_COSTO');
            $table->renameColumn('NOMBRE DE CUENTA', 'NOMBRE_DE_CUENTA');
            $table->renameColumn('MES SERVICIO', 'MES_SERVICIO');
        });
    }
};
