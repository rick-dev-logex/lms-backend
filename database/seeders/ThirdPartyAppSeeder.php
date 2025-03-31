<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\ThirdPartyApp;
use Illuminate\Support\Str;

class ThirdPartyAppSeeder extends Seeder
{
    public function run()
    {
        ThirdPartyApp::create([
            'name' => 'MiAppTercera',
            'app_key' => Str::random(32), // Genera una clave aleatoria
        ]);
    }
}
