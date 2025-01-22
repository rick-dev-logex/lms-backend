<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('requests', function (Blueprint $table) {
            $table->id();
            $table->string('unique_id')->unique(); // Prefijo 'g-' o 'd-' según sea gasto o descuento
            $table->enum('type', ['expense', 'discount']);
            $table->enum('status', ['pending', 'approved', 'rejected', 'review']);
            $table->date('request_date');
            $table->string('invoice_number');
            $table->foreignId('account_id')->constrained('accounts');
            $table->decimal('amount', 10, 2);
            $table->string('project');
            $table->string('responsible_id')->nullable();
            $table->unsignedBigInteger('transport_id')->nullable();
            $table->string('attachment_path')->nullable();
            $table->text('note');
            $table->timestamps();
            $table->softDeletes();

            // Índices para mejorar el rendimiento de las búsquedas
            $table->index('project');
            $table->index('responsible_id');
            $table->index('transport_id');
        });
    }

    public function down()
    {
        Schema::dropIfExists('requests');
    }
};
