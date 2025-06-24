<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('sri_requests', function (Blueprint $table) {
            $table->enum('status', ['pending', 'processed', 'error', 'skipped'])->change();
        });
    }

    public function down()
    {
        Schema::table('sri_requests', function (Blueprint $table) {
            $table->enum('status', ['pending', 'processed', 'error'])->default('pending')->change();
        });
    }
};
