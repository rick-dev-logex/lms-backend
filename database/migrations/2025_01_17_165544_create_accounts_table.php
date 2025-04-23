<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateAccountsTable extends Migration
{
    public function up()
    {
        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('account_number');
            $table->enum('account_type', ['nomina', 'transportista', 'ambos'])->default('nomina');
            $table->enum('account_status', ['active', 'inactive'])->default('active');
            $table->enum('account_affects', ['discount', 'expense', 'both'])->default('discount');
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('accounts');
    }
}
