<?php

// Asegúrate de que el archivo json contenga solo la data: {id: ...}. NO el JSON completo que devuelve la consulta {status, data[{id:..}]}

if (!file_exists('personal_data.json')) { // pon el archivo JSON que deseas que se genere el seeder
    die("Error: personal_data.json no encontrado\n");
}

$jsonFile = file_get_contents('personal_data.json'); // pon el archivo JSON que deseas que se genere el seeder
if (!$jsonFile) {
    die("Error: No se pudo leer el archivo\n");
}

$data = json_decode($jsonFile, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    die("Error al parsear JSON: " . json_last_error_msg() . "\n");
}

// ejecuta el comando php generate-seeder.php 
// Después de que se haya generado, ejecuta php artisan db:seed --class=PersonalSeeder (o el nombre que le pongas en la línea 36)

$seederContent = <<<EOD
<?php

namespace Database\Seeders;

use App\Models\Personal;
use Illuminate\Database\Seeder;

class PersonalSeeder extends Seeder
{
    public function run()
    {
        \$records = json_decode(file_get_contents(database_path('seeders/personal_data.json')), true);
        
        foreach (\$records as \$record) {
            // Convertir strings de booleanos a booleanos reales
            \$record = array_map(function(\$value) {
                if (\$value === "0") return false;
                if (\$value === "1") return true;
                return \$value;
            }, \$record);
            
            Personal::create(\$record);
        }
    }
}
EOD;

// Copiar el JSON a la carpeta de seeders
copy('personal_data.json', 'database/seeders/personal_data.json'); //Cambia el nombre de este también
file_put_contents('database/seeders/PersonalSeeder.php', $seederContent); // Y de este también

echo "Seeder generado exitosamente!\n";
