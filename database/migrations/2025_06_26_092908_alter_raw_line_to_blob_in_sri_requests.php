<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AlterRawLineToBlobInSriRequests extends Migration
{
    public function up()
    {
        Schema::table('sri_requests', function (Blueprint $table) {
            // Cambiamos raw_line de TEXT a LONGBLOB (sin collation)
            $table->binary('raw_line')->nullable()->change();
        });
    }

    public function down()
    {
        Schema::table('sri_requests', function (Blueprint $table) {
            // Si necesitas volver atrás, lo llevas a TEXT UTF8MB4
            $table->text('raw_line')->charset('utf8mb4')->collation('utf8mb4_unicode_ci')->change();
        });
    }
}
