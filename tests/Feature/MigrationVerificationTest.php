<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MigrationVerificationTest extends TestCase
{
    // NO usar RefreshDatabase aquí porque queremos probar la migración real
    
    /** @test */
    public function verify_foreign_key_constraint_exists()
    {
        // Verificar que la clave foránea existe
        $foreignKeys = DB::select("
            SELECT CONSTRAINT_NAME 
            FROM information_schema.KEY_COLUMN_USAGE 
            WHERE TABLE_NAME = 'requests' 
            AND COLUMN_NAME = 'reposicion_id' 
            AND REFERENCED_TABLE_NAME = 'reposiciones'
        ");

        $this->assertNotEmpty($foreignKeys, 'Foreign key constraint should exist');
    }

    /** @test */
    public function verify_column_type_is_correct()
    {
        // Verificar que la columna es del tipo correcto
        $columnInfo = DB::select("
            SELECT DATA_TYPE, IS_NULLABLE, COLUMN_DEFAULT 
            FROM information_schema.COLUMNS 
            WHERE TABLE_NAME = 'requests' 
            AND COLUMN_NAME = 'reposicion_id'
        ");

        $this->assertNotEmpty($columnInfo);
        $column = $columnInfo[0];
        
        $this->assertEquals('bigint', $column->DATA_TYPE);
        $this->assertEquals('YES', $column->IS_NULLABLE);
    }

    /** @test */
    public function verify_no_orphaned_requests()
    {
        // Verificar que no hay requests con reposicion_id que no existe
        $orphanedRequests = DB::select("
            SELECT r.id, r.unique_id, r.reposicion_id
            FROM requests r
            LEFT JOIN reposiciones rep ON r.reposicion_id = rep.id
            WHERE r.reposicion_id IS NOT NULL 
            AND rep.id IS NULL
        ");

        $this->assertEmpty($orphanedRequests, 'Should not have orphaned requests');
    }

    /** @test */
    public function verify_total_calculations_are_correct()
    {
        // Verificar que los totales de reposiciones coinciden con la suma de requests
        $mismatches = DB::select("
            SELECT 
                rep.id,
                rep.total_reposicion as repo_total,
                COALESCE(SUM(r.amount), 0) as calculated_total,
                ABS(rep.total_reposicion - COALESCE(SUM(r.amount), 0)) as difference
            FROM reposiciones rep
            LEFT JOIN requests r ON r.reposicion_id = rep.id AND r.deleted_at IS NULL
            GROUP BY rep.id, rep.total_reposicion
            HAVING ABS(rep.total_reposicion - COALESCE(SUM(r.amount), 0)) > 0.01
        ");

        if (!empty($mismatches)) {
            $this->fail('Found reposiciones with incorrect totals: ' . json_encode($mismatches));
        }
    }

    /** @test */
    public function verify_no_duplicate_requests_in_repositions()
    {
        // Verificar que no hay requests en múltiples reposiciones
        $duplicates = DB::select("
            SELECT unique_id, COUNT(DISTINCT reposicion_id) as repo_count
            FROM requests
            WHERE reposicion_id IS NOT NULL
            GROUP BY unique_id
            HAVING repo_count > 1
        ");

        $this->assertEmpty($duplicates, 'Should not have requests in multiple reposiciones');
    }

    /** @test */
    public function verify_detail_column_removed_from_reposiciones()
    {
        // Verificar que la columna detail fue removida
        $detailColumn = DB::select("
            SELECT COLUMN_NAME 
            FROM information_schema.COLUMNS 
            WHERE TABLE_NAME = 'reposiciones' 
            AND COLUMN_NAME = 'detail'
        ");

        $this->assertEmpty($detailColumn, 'Detail column should be removed from reposiciones table');
    }

    /** @test */
    public function verify_index_exists_on_reposicion_id()
    {
        // Verificar que existe un índice en reposicion_id
        $indexes = DB::select("
            SHOW INDEX FROM requests WHERE Column_name = 'reposicion_id'
        ");

        $this->assertNotEmpty($indexes, 'Index should exist on reposicion_id column');
    }

    /** @test */
    public function test_cascade_behavior()
    {
        // Crear datos de prueba para verificar el comportamiento de cascade
        DB::beginTransaction();
        
        try {
            // Crear una reposición temporal
            $reposicionId = DB::table('reposiciones')->insertGetId([
                'fecha_reposicion' => now(),
                'total_reposicion' => 100.00,
                'status' => 'pending',
                'project' => 'Test Project',
                'created_at' => now(),
                'updated_at' => now()
            ]);

            // Crear una request asociada
            $requestId = DB::table('requests')->insertGetId([
                'type' => 'expense',
                'personnel_type' => 'nomina',
                'project' => 'Test Project',
                'request_date' => now(),
                'invoice_number' => 'TEST-001',
                'account_id' => 'Test Account',
                'amount' => 100.00,
                'note' => 'Test note',
                'unique_id' => 'TEST-' . time(),
                'status' => 'in_reposition',
                'reposicion_id' => $reposicionId,
                'created_by' => 'Test User',
                'created_at' => now(),
                'updated_at' => now()
            ]);

            // Eliminar la reposición
            DB::table('reposiciones')->where('id', $reposicionId)->delete();

            // Verificar que la request tiene reposicion_id = NULL (por el onDelete('set null'))
            $request = DB::table('requests')->where('id', $requestId)->first();
            $this->assertNull($request->reposicion_id, 'Request should have null reposicion_id after parent deletion');

        } finally {
            DB::rollBack();
        }
    }
}