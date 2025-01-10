<?php

namespace Database\Seeders;

use App\Models\Personal;
use Illuminate\Database\Seeder;

class PersonalSeeder extends Seeder
{
    public function run()
    {
        $records = json_decode(file_get_contents(database_path('seeders/personal_data.json')), true);

        foreach ($records as $record) {
            // Convertir strings de booleanos a booleanos reales
            $record = array_map(function($value) {
                if ($value === "0") return false;
                if ($value === "1") return true;
                return $value;
            }, $record);

            // Validar y limpiar fechas en los registros
            foreach (['date_entered', 'date_modified', 'fecha_nacimiento', 'fecha_contrato', 'fecha_cesante'] as $dateField) {
                if (
                    isset($record[$dateField]) &&
                    ($record[$dateField] === '0000-00-00 00:00:00' || 
                     $record[$dateField] === '0000-00-00' || 
                     empty($record[$dateField]))
                ) {
                    $record[$dateField] = null;
                }
            }

            // Validar y limpiar cargos en los registros
            foreach (['cargo_logex'] as $cargo) {
                if (
                    isset($record[$cargo]) &&
                    ($record[$cargo] === "" || 
                     empty($record[$cargo]))
                ) {
                    $record[$cargo] = null;
                }
            }

            Personal::create($record);
        }
    }
}