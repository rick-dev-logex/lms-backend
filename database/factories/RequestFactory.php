<?php

namespace Database\Factories;

use App\Models\Request;
use Illuminate\Database\Eloquent\Factories\Factory;

class RequestFactory extends Factory
{
    protected $model = Request::class;

    public function definition(): array
    {
        $type = $this->faker->randomElement(['expense', 'discount', 'income']);
        
        // Valores válidos para month (formato YYYY-MM) - siempre obligatorio
        $validMonths = [
            '2025-01', '2025-02', '2025-03', '2025-04', '2025-05', '2025-06',
            '2025-07', '2025-08', '2025-09', '2025-10', '2025-11', '2025-12'
        ];
        
        // Valores válidos para when - solo para descuento, prestamo e ingreso
        $validWhen = ['liquidacion', 'decimo_tercero', 'decimo_cuarto', 'rol', 'utilidades'];
        
        // Valores válidos para project (4 caracteres) - siempre obligatorio
        $validProjects = ['ADNN', 'CNQT', 'CNGY', 'TONI', 'ARCA', 'MABE'];
        
        // Valores válidos para status
        $validStatus = ['pending', 'paid', 'rejected', 'deleted', 'in_reposition'];
        
        return [
            'type' => $type,
            'personnel_type' => 'nomina',
            'project' => $this->faker->randomElement($validProjects),
            'request_date' => $this->faker->dateTimeBetween('-30 days', 'now'),
            'month' => $this->faker->randomElement($validMonths), // Siempre obligatorio
            // when: obligatorio para descuento e ingreso, null para expense
            'when' => $type === 'expense' ? null : $this->faker->randomElement($validWhen),
            'invoice_number' => $this->faker->numerify('####'),
            'account_id' => 'Test Account',
            'amount' => $this->faker->randomFloat(2, 10, 1000),
            'note' => $this->faker->sentence(3), // Siempre obligatorio
            'unique_id' => $this->generateUniqueId($type),
            'responsible_id' => $this->faker->name(),
            'cedula_responsable' => $this->faker->numerify('##########'),
            'vehicle_plate' => null,
            'vehicle_number' => null,
            'status' => $this->faker->randomElement($validStatus),
            'reposicion_id' => null,
            'created_by' => $this->faker->name(),
            'updated_by' => null,
        ];
    }

    private function generateUniqueId(string $type): string
    {
        $prefix = match($type) {
            'income' => 'I-',
            'expense' => 'E-',
            'discount' => 'D-',
            default => 'E-'
        };
        
        return $prefix . $this->faker->unique()->numberBetween(1000, 9999);
    }

    // Estados para crear datos específicos
    public function expense(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'expense',
            'when' => null, // Para gastos, when es null
            'unique_id' => 'E-' . $this->faker->unique()->numberBetween(1000, 9999),
        ]);
    }

    public function discount(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'discount',
            'when' => $this->faker->randomElement(['liquidacion', 'decimo_tercero', 'decimo_cuarto', 'rol', 'utilidades']),
            'unique_id' => 'D-' . $this->faker->unique()->numberBetween(1000, 9999),
        ]);
    }

    public function income(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'income',
            'when' => $this->faker->randomElement(['liquidacion', 'decimo_tercero', 'decimo_cuarto', 'rol', 'utilidades']),
            'unique_id' => 'I-' . $this->faker->unique()->numberBetween(1000, 9999),
        ]);
    }

    public function withSpecificData(): static
    {
        return $this->state(fn (array $attributes) => [
            'project' => 'ADNN',
            'month' => '2025-05',
            'note' => 'Test expense note',
            'status' => 'pending',
        ]);
    }
}