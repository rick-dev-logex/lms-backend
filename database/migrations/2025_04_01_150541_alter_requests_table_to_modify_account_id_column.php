<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('requests', function (Blueprint $table) {
            // Intentar eliminar la clave foránea si existe, sin causar error si no está
            try {
                $table->dropForeign(['account_id']);
            } catch (\Exception $e) {
                // Ignorar si la clave foránea no existe
            }

            // Cambiar account_id a string
            $table->string('account_id', 255)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('requests', function (Blueprint $table) {
            // Revertir a bigint unsigned (tipo original de foreignId)
            $table->unsignedBigInteger('account_id')->nullable()->change();

            // Restaurar la clave foránea
            $table->foreign('account_id')->references('id')->on('accounts');
        });
    }
};
