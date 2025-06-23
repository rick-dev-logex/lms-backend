<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();

            // Identificación única
            $table->string('clave_acceso')->unique();

            // Emisor y comprador
            $table->string('ruc_emisor');
            $table->string('razon_social_emisor');
            $table->string('nombre_comercial_emisor')->nullable();
            $table->string('identificacion_comprador')->nullable();
            $table->string('razon_social_comprador');
            $table->string('direccion_comprador')->nullable();

            // Datos de factura
            $table->string('estab');
            $table->string('pto_emi');
            $table->string('secuencial');
            $table->string('invoice_serial');
            $table->string('ambiente');
            $table->timestamp('fecha_emision')->nullable();
            $table->timestamp('fecha_autorizacion')->nullable();
            $table->string('tipo_identificacion_comprador', 5)->nullable();
            $table->string('cod_doc', 5)->nullable(); // tipo de comprobante (01 = Factura)


            // Valores económicos
            $table->decimal('total_sin_impuestos', 10, 2)->default(0);
            $table->decimal('importe_total', 10, 2)->default(0);
            $table->decimal('iva', 10, 2)->nullable();
            $table->decimal('propina', 10, 2)->nullable();
            $table->string('moneda')->nullable();
            $table->string('forma_pago')->nullable();
            $table->string('placa')->nullable();

            // Campos editables
            $table->unsignedTinyInteger('mes');
            $table->string('project')->nullable();
            $table->string('centro_costo')->nullable();
            $table->text('notas')->nullable();
            $table->text('observacion')->nullable();
            $table->string('contabilizado', 100)->nullable();
            $table->string('tipo')->nullable();
            $table->string('proveedor_latinium')->nullable();
            $table->string('nota_latinium')->nullable();

            // Flujo del proceso
            $table->enum('estado', [
                'ingresada',
                'aprobada',
                'en_contabilidad',
                'contabilizada',
                'pagada',
                'actualizada',
                'no_conta'
            ])->default('ingresada');
            $table->string('numero_asiento')->nullable();
            $table->string('numero_transferencia')->nullable();
            $table->string('correo_pago')->nullable();

            // Asociación y almacenamiento
            $table->foreignId('purchase_order_id')->nullable()->constrained()->nullOnDelete();
            $table->string('empresa')->nullable();
            $table->string('xml_path')->nullable();
            $table->string('pdf_path')->nullable();

            $table->softDeletes();
            $table->timestamps();

            $table->index('empresa');
            $table->index('mes');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
