<?php

namespace Tests\Feature;

use App\Models\Request;
use App\Models\Reposicion;
use App\Models\Account;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class ReposicionTest extends TestCase
{
    use RefreshDatabase, WithFaker;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Crear datos de prueba básicos
        Account::factory()->create(['id' => 1, 'name' => 'Test Account']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function can_create_reposicion_with_requests()
    {
        // Crear requests de prueba
        $requests = Request::factory()->count(3)->create([
            'reposicion_id' => null,
            'status' => 'pending',
            'amount' => 100.00
        ]);

        $requestIds = $requests->pluck('unique_id')->toArray();

        // Simular archivo
        Storage::fake('local');
        $file = UploadedFile::fake()->create('test.pdf', 1024);

        $response = $this->postJson('/api/reposiciones', [
            'request_ids' => $requestIds,
            'attachment' => $file
        ]);

        $response->assertStatus(201);
        
        // Verificar que la reposición se creó correctamente
        $this->assertDatabaseHas('reposiciones', [
            'total_reposicion' => 300.00,
            'status' => 'pending'
        ]);

        // Verificar que las requests se asociaron correctamente
        foreach ($requests as $request) {
            $this->assertDatabaseHas('requests', [
                'id' => $request->id,
                'status' => 'in_reposition'
            ]);
        }
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function can_calculate_total_from_associated_requests()
    {
        $reposicion = Reposicion::factory()->create();
        
        // Crear requests con diferentes montos
        Request::factory()->create([
            'reposicion_id' => $reposicion->id,
            'amount' => 150.50
        ]);
        Request::factory()->create([
            'reposicion_id' => $reposicion->id,
            'amount' => 200.25
        ]);
        Request::factory()->create([
            'reposicion_id' => $reposicion->id,
            'amount' => 75.00
        ]);

        $calculatedTotal = $reposicion->calculateTotal();
        
        $this->assertEquals(425.75, $calculatedTotal);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function requests_relationship_works_correctly()
    {
        $reposicion = Reposicion::factory()->create();
        $requests = Request::factory()->count(3)->create([
            'reposicion_id' => $reposicion->id
        ]);

        $relationshipRequests = $reposicion->requests;

        $this->assertCount(3, $relationshipRequests);
        $this->assertTrue($relationshipRequests->contains('id', $requests->first()->id));
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function detail_attribute_returns_request_unique_ids()
    {
        $reposicion = Reposicion::factory()->create();
        $requests = Request::factory()->count(3)->create([
            'reposicion_id' => $reposicion->id
        ]);

        $expectedIds = $requests->pluck('unique_id')->sort()->values()->toArray();
        $actualIds = collect($reposicion->detail)->sort()->values()->toArray();

        $this->assertEquals($expectedIds, $actualIds);
    }
}

// Test para Request
class RequestTest extends TestCase
{
    use RefreshDatabase, WithFaker;

    #[\PHPUnit\Framework\Attributes\Test]
    public function request_belongs_to_reposicion()
    {
        $reposicion = Reposicion::factory()->create();
        $request = Request::factory()->create([
            'reposicion_id' => $reposicion->id
        ]);

        $this->assertEquals($reposicion->id, $request->reposicion->id);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function pending_reposition_scope_works()
    {
        // Requests sin reposición
        Request::factory()->count(2)->create([
            'reposicion_id' => null,
            'status' => 'approved'
        ]);

        // Requests con reposición
        $reposicion = Reposicion::factory()->create();
        Request::factory()->create([
            'reposicion_id' => $reposicion->id,
            'status' => 'in_reposition'
        ]);

        $pendingRequests = Request::pendingReposition()->get();
        
        $this->assertCount(2, $pendingRequests);
    }
}

// Test de verificación de migración simplificado
class MigrationVerificationTest extends TestCase
{
    // NO usar RefreshDatabase aquí

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
}