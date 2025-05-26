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
        Schema::table('reposiciones', function (Blueprint $table) {
            $table->dropColumn('detail');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('reposiciones', function (Blueprint $table) {
            $table->addColumn('detail', 500)->after('project')->nullable();
        });
    }
};
