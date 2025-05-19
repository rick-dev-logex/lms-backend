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
            $table->softDeletes();
            $table->string('estado')->default('PENDIENTE')->after('identificacion_receptor');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sri_documents', function (Blueprint $table) {
            $table->dropSoftDeletes();
            $table->dropColumn('estado');
        });
    }
};
