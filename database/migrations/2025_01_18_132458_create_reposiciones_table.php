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
            $table->timestamp('fecha_reposicion')->nullable();
            $table->decimal('total_reposicion', 10, 2)->default(0);
            $table->enum('status', ['pending', 'paid', 'rejected', 'review', 'deleted'])->default('pending');
            $table->string('project', 100)->nullable();
            $table->longText('detail')->nullable(); // Cambiado de json a longText
            $table->string('month', 7)->nullable();
            $table->enum('when', ['rol', 'liquidación', 'decimo_tercero', 'decimo_cuarto', 'utilidades'])->nullable();
            $table->text('attachment_url')->nullable();
            $table->string('attachment_name')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index('fecha_reposicion');
            $table->index(['project', 'status']);
            $table->index(['status', 'month']);
            $table->index(['month', 'when']);
            $table->index(['status', 'created_at']);
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
