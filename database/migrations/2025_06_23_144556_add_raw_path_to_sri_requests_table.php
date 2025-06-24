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
        Schema::table('sri_requests', function (Blueprint $table) {
            $table->string('raw_path')->after('id')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down()
    {
        Schema::table('sri_requests', function (Blueprint $table) {
            $table->dropColumn('raw_path');
        });
    }
};
