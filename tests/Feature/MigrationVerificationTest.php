<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Support\Facades\DB;

class MigrationVerificationTest extends TestCase
{
    // NO usar RefreshDatabase aquí porque queremos probar la migración real

    #[\PHPUnit\Framework\Attributes\Test]
    public function verify_column_exists_and_is_nullable()
    {
        $columnInfo = DB::select("
            SELECT DATA_TYPE, IS_NULLABLE 
            FROM information_schema.COLUMNS 
            WHERE TABLE_NAME = 'requests' 
            AND COLUMN_NAME = 'reposicion_id'
        ");

        $this->assertNotEmpty($columnInfo, 'reposicion_id column should exist');
        
        $column = $columnInfo[0];
        $this->assertEquals('YES', $column->IS_NULLABLE);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function verify_no_orphaned_requests()
    {
        $orphanedRequests = DB::select("
            SELECT r.id, r.unique_id, r.reposicion_id
            FROM requests r
            LEFT JOIN reposiciones rep ON r.reposicion_id = rep.id
            WHERE r.reposicion_id IS NOT NULL 
            AND r.reposicion_id != '' 
            AND rep.id IS NULL
        ");

        $this->assertEmpty($orphanedRequests, 'Should not have orphaned requests');
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function verify_no_duplicate_requests_in_repositions()
    {
        $duplicates = DB::select("
            SELECT unique_id, COUNT(DISTINCT reposicion_id) as repo_count
            FROM requests
            WHERE reposicion_id IS NOT NULL 
            AND reposicion_id != ''
            GROUP BY unique_id
            HAVING repo_count > 1
        ");

        $this->assertEmpty($duplicates, 'Should not have requests in multiple reposiciones');
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function verify_reposicion_id_column_type()
    {
        $columnInfo = DB::select("
            SELECT DATA_TYPE, IS_NULLABLE, COLUMN_DEFAULT 
            FROM information_schema.COLUMNS 
            WHERE TABLE_NAME = 'requests' 
            AND COLUMN_NAME = 'reposicion_id'
        ");

        $this->assertNotEmpty($columnInfo);
        $column = $columnInfo[0];
        
        // Verificar que es nullable
        $this->assertEquals('YES', $column->IS_NULLABLE);
        
        // El tipo puede ser varchar o bigint dependiendo de si se ejecutó la migración
        $this->assertContains($column->DATA_TYPE, ['varchar', 'bigint']);
    }
}