<?php

namespace Database\Factories;

use App\Models\Request;
use App\Models\Account;
use App\Services\UniqueIdService;
use Illuminate\Database\Eloquent\Factories\Factory;

class RequestFactory extends Factory
{
    protected $model = Request::class;

    public function definition(): array
    {
        $type = $this->faker->randomElement(['expense', 'discount', 'income']);
        
        return [
            'type' => $type,
            'personnel_type' => $this->faker->randomElement(['nomina', 'transportista']),
            'project' => $this->faker->company("####"),
            'request_date' => $this->faker->dateTimeBetween('-30 days', 'now'),
            'month' => $this->faker->monthName(),
            'when' => $this->faker->randomElement(['rol', 'liquidación', 'decimo_tercero', 'decimo_cuarto', 'utilidades']),
            'invoice_number' => $this->faker->numerify('####'),
            'account_id' => Account::factory(),
            'amount' => $this->faker->randomFloat(2, 10, 1000),
            'note' => $this->faker->sentence(),
            'unique_id' => $this->generateUniqueId($type),
            'responsible_id' => $this->faker->name(),
            'cedula_responsable' => $this->faker->numerify('##########'),
            'vehicle_plate' => $this->faker->bothify('???-####'),
            'vehicle_number' => $this->faker->numberBetween(1, 100),
            'status' => $this->faker->randomElement(['pending', 'approved', 'rejected', 'in_reposition', 'paid']),
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

    public function expense(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'expense',
            'unique_id' => 'E-' . $this->faker->unique()->numberBetween(1000, 9999),
        ]);
    }

    public function income(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'income',
            'unique_id' => 'I-' . $this->faker->unique()->numberBetween(1000, 9999),
        ]);
    }

    public function discount(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'discount',
            'unique_id' => 'D-' . $this->faker->unique()->numberBetween(1000, 9999),
        ]);
    }

    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'pending',
            'reposicion_id' => null,
        ]);
    }

    public function inReposition(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'in_reposition',
        ]);
    }
}