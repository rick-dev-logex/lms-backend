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
        Schema::create('reposicion_proyecto', function (Blueprint $table) {
            $table->foreignId('reposicion_id')->constrained()->onDelete('cascade');
            $table->uuid('proyecto_id');
            $table->string('proyecto_nombre'); // Opcional pero útil para evitar hacer join con otra base
            $table->primary(['reposicion_id', 'proyecto_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('reposicion_proyecto');
    }
};
