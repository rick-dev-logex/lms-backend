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
        Schema::table('invoices', function (Blueprint $table) {
            // ----------- Informacion tributaria ----------------------
            $table->string('tipo_emision', 100)->after('ambiente')->nullable();
            $table->string('dir_matriz', 100)->after('secuencial')->nullable();
            $table->string('agente_retencion', 100)->after('dir_matriz')->nullable();

            // ----------- Informacion factura ----------------------
            $table->string('dir_establecimiento', 100)->after('fecha_emision')->nullable();
            $table->string('contribuyente_especial', 100)->after('dir_establecimiento')->nullable();
            $table->string('obligado_contabilidad', 100)->after('contribuyente_especial')->nullable();
            $table->decimal('total_descuento', 10, 2)->after('total_sin_impuestos')->default(0.00);
            $table->tinyInteger('codigo')->after('total_descuento')->default(0);
            $table->tinyInteger('codigo_porcentaje')->after('codigo')->nullable();
            $table->decimal('descuento_adicional', 10, 2)->after('codigo_porcentaje')->default(0.00);
            $table->decimal('base_imponible_factura', 10, 2)->after('descuento_adicional')->default(0.00);
            $table->decimal('valor_factura', 10, 2)->after('base_imponible_factura')->default(0.00);
            $table->decimal('total', 10, 2)->after('forma_pago')->default(0.00);
            $table->tinyInteger('plazo')->after('total')->default(0);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('invoice', function (Blueprint $table) {
            $table->dropColumn('tipo_emision');
            $table->dropColumn('dir_matriz');
            $table->dropColumn('agente_retencion');
            $table->dropColumn('total');
            $table->dropColumn('plazo');
            $table->dropColumn('dir_establecimiento');
            $table->dropColumn('contribuyente_especial');
            $table->dropColumn('obligado_contabilidad');
            $table->dropColumn('total_descuento');
            $table->dropColumn('codigo');
            $table->dropColumn('codigo_porcentaje');
            $table->dropColumn('descuento_adicional');
            $table->dropColumn('base_imponible_factura');
            $table->dropColumn('valor_factura');
        });
    }
};
