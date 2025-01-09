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
            
            Personal::create($record);
        }
    }
}