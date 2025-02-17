<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up()
    {
        Schema::create('user_assigned_projects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')
                ->constrained('users')
                ->onDelete('cascade');
            // Usamos LONGTEXT para almacenar los IDs serializados en formato JSON (aunque MariaDB no tenga el tipo nativo)
            $table->longText('projects')->nullable();
            $table->timestamps();
            // Asegura que cada usuario tenga un único registro
            $table->unique('user_id');
        });
    }

    public function down()
    {
        Schema::dropIfExists('user_assigned_projects');
    }
};
