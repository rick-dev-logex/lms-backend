<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Limpiar valores inválidos primero
        DB::statement("UPDATE requests SET reposicion_id = NULL WHERE reposicion_id = '' OR reposicion_id = '0'");
        
        Schema::table('requests', function (Blueprint $table) {
            // Cambiar el tipo de columna de string a unsignedBigInteger
            $table->unsignedBigInteger('reposicion_id')->nullable()->change();
            
            // Agregar la clave foránea
            $table->foreign('reposicion_id')->references('id')->on('reposiciones')->onDelete('set null');
            
            // Agregar índice para mejor rendimiento
            $table->index('reposicion_id');
        });

        // Opcional: Remover la columna detail de reposiciones
        Schema::table('reposiciones', function (Blueprint $table) {
            if (Schema::hasColumn('reposiciones', 'detail')) {
                $table->dropColumn('detail');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('requests', function (Blueprint $table) {
            $table->dropForeign(['reposicion_id']);
            $table->dropIndex(['reposicion_id']);
            $table->string('reposicion_id')->nullable()->change();
        });

        Schema::table('reposiciones', function (Blueprint $table) {
            $table->text('detail')->nullable();
        });
    }
};