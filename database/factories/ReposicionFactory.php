<?php

namespace Database\Factories;

use App\Models\Reposicion;
use Illuminate\Database\Eloquent\Factories\Factory;
use Carbon\Carbon;

class ReposicionFactory extends Factory
{
    protected $model = Reposicion::class;

    public function definition(): array
    {
        // Valores válidos para project (4 caracteres)
        $validProjects = ['ADNN', 'CNQT', 'CNGY', 'TONI', 'ARCA', 'MABE'];
        return [
            'fecha_reposicion' => Carbon::now(),
            'total_reposicion' => $this->faker->randomFloat(2, 100, 5000),
            'status' => 'pending',
            'project' => $this->faker->randomElement($validProjects),
            'month' => null,
            'when' => null,
            'note' => null,
            'attachment_url' => null,
            'attachment_name' => null,
        ];
    }
}
