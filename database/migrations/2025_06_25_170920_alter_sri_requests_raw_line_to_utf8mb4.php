<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AlterSriRequestsRawLineToUtf8mb4 extends Migration
{
    public function up()
    {
        Schema::table('sri_requests', function (Blueprint $table) {
            // Cambiamos raw_line a TEXT utf8mb4
            $table->text('raw_line')
                ->charset('utf8mb4')
                ->collation('utf8mb4_unicode_ci')
                ->change();
        });
    }

    public function down()
    {
        Schema::table('sri_requests', function (Blueprint $table) {
            // Si antes era varchar (ajusta longitud si era distinta)
            $table->string('raw_line', 255)
                ->charset('utf8')
                ->collation('utf8_unicode_ci')
                ->change();
        });
    }
}
