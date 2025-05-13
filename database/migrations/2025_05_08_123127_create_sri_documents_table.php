<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('sri_documents', function (Blueprint $table) {
            $table->id();
            $table->string('clave_acceso')->unique();
            $table->string('ruc_emisor')->index();
            $table->string('razon_social_emisor');
            $table->string('tipo_comprobante');
            $table->string('serie_comprobante');
            $table->string('nombre_xml');
            $table->string('nombre_pdf');
            $table->string('gcs_path_xml')->nullable();
            $table->string('gcs_path_pdf')->nullable();
            $table->timestamp('fecha_autorizacion')->nullable();
            $table->timestamp('fecha_emision')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sri_documents');
    }
};
