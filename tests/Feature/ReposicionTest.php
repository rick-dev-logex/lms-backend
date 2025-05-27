<?php

// tests/Feature/ReposicionTest.php
namespace Tests\Feature;

use App\Models\Request;
use App\Models\Reposicion;
use App\Models\Account;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

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
        // En lugar de usar la API, crear directamente en base de datos
        $reposicion = Reposicion::factory()->create([
            'total_reposicion' => 300.00,
            'status' => 'pending'
        ]);

        // Crear requests asociadas (mix de diferentes tipos)
        Request::factory()->expense()->create([
            'reposicion_id' => $reposicion->id,
            'status' => 'in_reposition',
            'amount' => 100.00
        ]);

        Request::factory()->discount()->create([
            'reposicion_id' => $reposicion->id,
            'status' => 'in_reposition',
            'amount' => 100.00
        ]);

        Request::factory()->income()->create([
            'reposicion_id' => $reposicion->id,
            'status' => 'in_reposition',
            'amount' => 100.00
        ]);

        // Verificar que la reposición se creó correctamente
        $this->assertDatabaseHas('reposiciones', [
            'id' => $reposicion->id,
            'total_reposicion' => 300.00,
            'status' => 'pending'
        ]);

        // Verificar que las requests se asociaron correctamente
        $this->assertCount(3, $reposicion->requests);
        
        // Verificar que el cálculo de total funciona
        $this->assertEquals(300.00, $reposicion->calculateTotal());
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function reposicion_status_transitions_correctly()
    {
        $reposicion = Reposicion::factory()->create([
            'status' => 'pending'
        ]);

        // Verificar estados válidos para reposicion
        $validStatuses = ['pending', 'paid', 'rejected', 'deleted'];
        
        foreach ($validStatuses as $status) {
            $reposicion->update(['status' => $status]);
            $this->assertEquals($status, $reposicion->fresh()->status);
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