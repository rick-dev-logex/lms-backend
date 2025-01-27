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
        Schema::create('presupuestos', function (Blueprint $table) {
            $table->id();
            $table->decimal('valor', 10, 2);
            $table->string('proyecto')->constrained('proyectos');
            $table->string('cuenta')->constrained('accounts');
            $table->date('mes_de_servicio');
            $table->timestamps();
        });

        //TODO: Cargar excel con los datos de presupuestos
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('presupuestos');
    }
};
