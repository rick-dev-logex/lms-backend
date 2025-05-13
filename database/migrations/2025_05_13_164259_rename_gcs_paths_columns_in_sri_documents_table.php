<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('sri_documents', function (Blueprint $table) {
            // Renombrar las columnas para reflejar su nuevo propósito
            $table->renameColumn('gcs_path_xml', 'xml_path_identifier');
            $table->renameColumn('gcs_path_pdf', 'pdf_path_identifier');

            // Agregar un comentario a la tabla para documentar el cambio (para cuando usemos pgsql)
            // DB::statement("COMMENT ON COLUMN sri_documents.xml_path_identifier IS 'Identificador para la generación dinámica del XML, ya no apunta a una ruta física'");
            // DB::statement("COMMENT ON COLUMN sri_documents.pdf_path_identifier IS 'Identificador para la generación dinámica del PDF, ya no apunta a una ruta física'");
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sri_documents', function (Blueprint $table) {
            // Revertir el cambio de nombre
            $table->renameColumn('xml_path_identifier', 'gcs_path_xml');
            $table->renameColumn('pdf_path_identifier', 'gcs_path_pdf');

            // Eliminar los comentarios
            // if (DB::getDriverName() === 'pgsql') {
            //     DB::statement("COMMENT ON COLUMN sri_documents.gcs_path_xml IS NULL");
            //     DB::statement("COMMENT ON COLUMN sri_documents.gcs_path_pdf IS NULL");
            // }
        });
    }
};
