<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AlterRawLineAndErrorMessageInSriRequestsTable extends Migration
{
    public function up()
    {
        Schema::table('sri_requests', function (Blueprint $table) {
            // cambia raw_line de VARCHAR a TEXT
            $table->text('raw_line')->change();
            // opcional: si error_message era VARCHAR corto, lo pasamos a TEXT también
            $table->text('error_message')->nullable()->change();
        });
    }

    public function down()
    {
        Schema::table('sri_requests', function (Blueprint $table) {
            // vuelve raw_line a varchar(1000) o el tamaño original
            $table->string('raw_line', 1000)->change();
            $table->string('error_message', 1000)->nullable()->change();
        });
    }
}
