<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('positions', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->json('permissions');
            $table->text('description')->nullable();
            $table->boolean('status')->default(true); // Activo/Inactivo
            $table->foreignId('created_by')->nullable()->constrained('users')->onDelete('set null'); // Usuario creador
            $table->foreignId('updated_by')->nullable()->constrained('users')->onDelete('set null'); // Usuario actualizador
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('positions');
    }
};
