<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('requests', function (Blueprint $table) {
            // Índices compuestos para consultas frecuentes
            $table->index(['status', 'created_at'], 'idx_requests_status_created');
            $table->index(['project', 'status'], 'idx_requests_project_status');
            $table->index(['type', 'status', 'created_at'], 'idx_requests_type_status_created');
            $table->index(['reposicion_id', 'status'], 'idx_requests_repo_status');
            $table->index(['unique_id', 'status'], 'idx_requests_unique_status');
            $table->index(['request_date', 'project'], 'idx_requests_date_project');
            
            // Índice para evitar duplicados en validaciones
            $table->index([
                'type', 'project', 'request_date', 'invoice_number', 
                'account_id', 'amount'
            ], 'idx_requests_duplicate_check');
        });

        Schema::table('reposiciones', function (Blueprint $table) {
            $table->index(['status', 'created_at'], 'idx_reposiciones_status_created');
            $table->index(['project', 'status'], 'idx_reposiciones_project_status');
            $table->index(['fecha_reposicion', 'status'], 'idx_reposiciones_fecha_status');
        });
    }

    public function down(): void
    {
        Schema::table('requests', function (Blueprint $table) {
            $table->dropIndex('idx_requests_status_created');
            $table->dropIndex('idx_requests_project_status');
            $table->dropIndex('idx_requests_type_status_created');
            $table->dropIndex('idx_requests_repo_status');
            $table->dropIndex('idx_requests_unique_status');
            $table->dropIndex('idx_requests_date_project');
            $table->dropIndex('idx_requests_duplicate_check');
        });

        Schema::table('reposiciones', function (Blueprint $table) {
            $table->dropIndex('idx_reposiciones_status_created');
            $table->dropIndex('idx_reposiciones_project_status');
            $table->dropIndex('idx_reposiciones_fecha_status');
        });
    }
};