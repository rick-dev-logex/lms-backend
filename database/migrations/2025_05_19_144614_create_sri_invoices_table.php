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
        Schema::create('sri_invoices', function (Blueprint $table) {
            $table->id();
            $table->string('provider')->nullable();
            $table->string('sustento_code')->nullable();
            $table->string('provider_id_type')->nullable();
            $table->string('ruc')->nullable();
            $table->string('invoice_type')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sri_invoices');
    }
};
