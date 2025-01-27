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
        Schema::create('reposiciones', function (Blueprint $table) {
            $table->id();
            $table->foreignID('request_id')->constrained('requests')->onDelete('cascade');
            $table->string('fecha_reposicion')->nullable();
            $table->string('total_reposicion')->default(0);
            $table->string('status')->default('pending');
            $table->string('project')->nullable();
            $table->json('detail');
            $table->string('month')->nullable();
            $table->enum('when', ['rol', 'liquidación', 'decimo_tercero', 'decimo_cuarto', 'utilidades'])->default('rol');
            $table->string('note')->nullable();
            $table->timestamps();

            $table->index('project');
            $table->index('status');
            $table->index('month');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('reposiciones');
    }
};
