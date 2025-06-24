<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up()
    {
        Schema::create('sri_requests', function (Blueprint $table) {
            $table->id();
            $table->string('raw_line');
            $table->string('clave_acceso')->index();
            $table->enum('status', ['pending', 'processed', 'error'])->default('pending');
            $table->text('error_message')->nullable();
            $table->foreignId('invoice_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sri_requests');
    }
};
