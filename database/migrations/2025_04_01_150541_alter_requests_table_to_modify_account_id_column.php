<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Eliminar la clave foránea temporalmente
        Schema::table('requests', function (Blueprint $table) {
            $table->dropForeign('requests_account_id_foreign');
        });

        // Cambiar el tipo de account_id a varchar
        Schema::table('requests', function (Blueprint $table) {
            $table->string('account_id', 255)->nullable()->change();
        });

        // Opcional: No recrear la clave foránea si ahora se guardan cadenas raw
        // Si aún necesitas la clave foránea, ajusta la tabla accounts y recrea la relación
    }

    public function down(): void
    {
        // Revertir el cambio a entero
        Schema::table('requests', function (Blueprint $table) {
            $table->unsignedBigInteger('account_id')->nullable()->change();
        });

        // Restaurar la clave foránea
        Schema::table('requests', function (Blueprint $table) {
            $table->foreign('account_id')->references('id')->on('accounts');
        });
    }
};
