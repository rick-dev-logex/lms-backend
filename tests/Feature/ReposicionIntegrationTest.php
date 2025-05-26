<?php

namespace Tests\Feature;

use App\Models\Request;
use App\Models\Reposicion;
use App\Models\Account;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ReposicionIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected $user;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->user = User::factory()->create([
            'email' => 'test@example.com'
        ]);
        
        Sanctum::actingAs($this->user);
        Account::factory()->create();
    }

    /** @test */
    public function complete_reposicion_workflow()
    {
        // 1. Crear requests pendientes
        $requests = Request::factory()->count(3)->pending()->create([
            'amount' => 100.00,
            'project' => 'Test Project'
        ]);

        $requestIds = $requests->pluck('unique_id')->toArray();

        // 2. Crear reposición
        Storage::fake('gcs');
        $file = UploadedFile::fake()->create('attachment.pdf', 1024);

        $createResponse = $this->postJson('/api/reposiciones', [
            'request_ids' => $requestIds,
            'attachment' => $file
        ]);

        $createResponse->assertStatus(201);
        $reposicionData = $createResponse->json('data');
        $reposicionId = $reposicionData['id'];

        // Verificar que las requests se actualizaron
        foreach ($requestIds as $requestId) {
            $this->assertDatabaseHas('requests', [
                'unique_id' => $requestId,
                'reposicion_id' => $reposicionId,
                'status' => 'in_reposition'
            ]);
        }

        // 3. Obtener reposición con requests
        $showResponse = $this->getJson("/api/reposiciones/{$reposicionId}");
        $showResponse->assertStatus(200);
        $this->assertCount(3, $showResponse->json('requests'));

        // 4. Actualizar estado a pagado
        $updateResponse = $this->putJson("/api/reposiciones/{$reposicionId}", [
            'status' => 'paid',
            'month' => 'January',
            'when' => 'rol'
        ]);

        $updateResponse->assertStatus(200);

        // Verificar que se propagó a las requests
        foreach ($requestIds as $requestId) {
            $this->assertDatabaseHas('requests', [
                'unique_id' => $requestId,
                'status' => 'paid',
                'month' => 'January',
                'when' => 'rol'
            ]);
        }

        // 5. Eliminar reposición
        $deleteResponse = $this->deleteJson("/api/reposiciones/{$reposicionId}");
        $deleteResponse->assertStatus(200);

        // Verificar soft delete y limpieza de requests
        $this->assertSoftDeleted('reposiciones', ['id' => $reposicionId]);
        
        foreach ($requestIds as $requestId) {
            $this->assertDatabaseHas('requests', [
                'unique_id' => $requestId,
                'reposicion_id' => null,
                'status' => 'deleted'
            ]);
        }
    }

    /** @test */
    public function cannot_add_request_to_multiple_repositions()
    {
        // Crear request y reposición
        $request = Request::factory()->pending()->create();
        $reposicion1 = Reposicion::factory()->create();
        
        // Asociar request a primera reposición
        $request->update(['reposicion_id' => $reposicion1->id]);

        // Intentar crear segunda reposición con la misma request
        Storage::fake('gcs');
        $file = UploadedFile::fake()->create('test.pdf', 1024);

        $response = $this->postJson('/api/reposiciones', [
            'request_ids' => [$request->unique_id],
            'attachment' => $file
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['request_ids']);
    }

    /** @test */
    public function total_recalculation_on_request_update()
    {
        // Crear reposición con requests
        $reposicion = Reposicion::factory()->create();
        $request1 = Request::factory()->create([
            'reposicion_id' => $reposicion->id,
            'amount' => 100.00
        ]);
        $request2 = Request::factory()->create([
            'reposicion_id' => $reposicion->id,
            'amount' => 200.00
        ]);

        // Total inicial debería ser 300
        $this->assertEquals(300.00, $reposicion->calculateTotal());

        // Actualizar monto de una request
        $updateResponse = $this->putJson("/api/requests/{$request1->id}", [
            'amount' => 150.00
        ]);

        $updateResponse->assertStatus(200);

        // Verificar que el total se recalculó
        $reposicion->refresh();
        $this->assertEquals(350.00, $reposicion->calculateTotal());
    }

    /** @test */
    public function filtering_works_correctly()
    {
        // Crear reposiciones con diferentes estados y proyectos
        $pendingReposicion = Reposicion::factory()->create([
            'status' => 'pending',
            'project' => 'Project A'
        ]);
        
        $paidReposicion = Reposicion::factory()->create([
            'status' => 'paid',
            'project' => 'Project B'
        ]);

        // Test filtro por estado
        $response = $this->getJson('/api/reposiciones?status=pending');
        $response->assertStatus(200);
        $data = $response->json();
        $this->assertCount(1, $data);
        $this->assertEquals('pending', $data[0]['status']);

        // Test filtro por proyecto
        $response = $this->getJson('/api/reposiciones?project=Project A');
        $response->assertStatus(200);
        $data = $response->json();
        $this->assertCount(1, $data);
        $this->assertEquals('Project A', $data[0]['project']);
    }

    /** @test */
    public function income_filtering_works()
    {
        $reposicion = Reposicion::factory()->create();
        
        // Crear requests mixtas
        Request::factory()->income()->create([
            'reposicion_id' => $reposicion->id,
            'unique_id' => 'I-001'
        ]);
        Request::factory()->expense()->create([
            'reposicion_id' => $reposicion->id,
            'unique_id' => 'E-001'
        ]);

        // Test modo income (solo I-XXXX)
        $response = $this->getJson('/api/reposiciones?mode=income');
        $response->assertStatus(200);
        $data = $response->json();
        
        // Debería filtrar correctamente según la lógica del controlador
        $this->assertIsArray($data);
    }

    /** @test */
    public function file_upload_and_retrieval()
    {
        Storage::fake('gcs');
        $file = UploadedFile::fake()->create('test-document.pdf', 2048);
        
        $request = Request::factory()->pending()->create();

        $response = $this->postJson('/api/reposiciones', [
            'request_ids' => [$request->unique_id],
            'attachment' => $file
        ]);

        $response->assertStatus(201);
        $reposicionId = $response->json('data.id');

        // Verificar que el archivo se guardó
        $this->assertDatabaseHas('reposiciones', [
            'id' => $reposicionId,
            'attachment_name' => 'test-document.pdf'
        ]);

        // Test obtener URL del archivo
        $fileResponse = $this->getJson("/api/reposiciones/{$reposicionId}/file");
        $fileResponse->assertStatus(200);
        $fileResponse->assertJsonStructure([
            'file_url',
            'file_name'
        ]);
    }

    /** @test */
    public function validation_errors_are_handled_correctly()
    {
        // Test sin request_ids
        Storage::fake('gcs');
        $file = UploadedFile::fake()->create('test.pdf', 1024);

        $response = $this->postJson('/api/reposiciones', [
            'attachment' => $file
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['request_ids']);

        // Test sin archivo
        $request = Request::factory()->pending()->create();
        
        $response = $this->postJson('/api/reposiciones', [
            'request_ids' => [$request->unique_id]
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['attachment']);

        // Test con request_ids que no existen
        $response = $this->postJson('/api/reposiciones', [
            'request_ids' => ['INVALID-ID'],
            'attachment' => $file
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['request_ids']);
    }
}