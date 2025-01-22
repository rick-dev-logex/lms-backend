<?php

namespace Database\Seeders;

use App\Models\Area;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class AreaSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $areas = [
            ['name' => 'admin', 'description' => 'Área encargada de la gestión de administración'],
            ['name' => 'bodega', 'description' => 'Área encargada de la gestión de bodega'],
            ['name' => 'griferia', 'description' => 'Área encargada de la gestión de grifería'],
            ['name' => 'monitoreo', 'description' => 'Área encargada de la gestión de monitoreo'],
            ['name' => 'porteo', 'description' => 'Área encargada de la gestión de porteo'],
            ['name' => 'proveedor', 'description' => 'Área encargada de la gestión de proveedor'],
            ['name' => 'sanitarios', 'description' => 'Área encargada de la gestión de sanitarios'],
            ['name' => 'temporal', 'description' => 'Área encargada de la gestión de temporal'],
            ['name' => 'transporte', 'description' => 'Área encargada de la gestión de transporte'],
        ];

        foreach ($areas as $area) {
            Area::create($area);
        }
    }
}
