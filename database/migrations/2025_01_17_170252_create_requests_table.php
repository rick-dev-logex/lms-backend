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
            $table->enum('type', ['expense', 'discount', 'income']);
            $table->string('personnel_type');
            $table->enum('status', ['pending', 'paid', 'rejected', 'review', 'in_reposition', 'deleted']);
            $table->date('request_date');
            $table->string('invoice_number');
            $table->foreignId('account_id')->constrained('accounts');
            $table->decimal('amount', 10, 2);
            $table->string('project');
            $table->string('responsible_id')->nullable();
            $table->string('transport_id')->nullable();
            $table->text('note');
            $table->timestamps();
            $table->softDeletes();

            // Índices para mejorar el rendimiento de las búsquedas
            $table->index('project');
            $table->index('responsible_id');
            $table->index('transport_id');
            $table->index(['status', 'created_at']);
            $table->index(['type', 'status']);
            $table->index(['personnel_type', 'status']);
            $table->index('request_date');
            $table->index(['project', 'status', 'type']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('requests');
    }
};
