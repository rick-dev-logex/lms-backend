<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up()
    {
        Schema::create('third_party_apps', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique(); // Nombre de la app
            $table->string('app_key')->unique(); // Clave secreta
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('third_party_apps');
    }
};
