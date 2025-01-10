<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class CargoSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        // Leer el archivo JSON desde storage/app
        $json = file_get_contents(storage_path('app/unique_cargos.json'));
        
        // Convertir el contenido JSON a un array de PHP
        $cargos = json_decode($json, true);

        // Insertar los datos en la tabla 'cargos'
        DB::table('cargos')->insert($cargos);
    }
}
