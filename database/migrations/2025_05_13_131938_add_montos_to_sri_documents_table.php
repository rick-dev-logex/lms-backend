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
        Schema::table('sri_documents', function (Blueprint $table) {
            $table->decimal('valor_sin_impuestos', 10, 2)->nullable();
            $table->decimal('iva', 10, 2)->nullable();
            $table->decimal('importe_total', 10, 2)->nullable();
            $table->string('identificacion_receptor')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sri_documents', function (Blueprint $table) {
            $table->dropColumn('valor_sin_impuestos');
            $table->dropColumn('iva');
            $table->dropColumn('importe_total');
            $table->dropColumn('identificacion_receptor');
        });
    }
};
