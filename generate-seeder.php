<?php

// Asegúrate de que el archivo JSON contenga solo la data relevante: [{id: ...}, {id: ...}]
if (!file_exists('personal_data.json')) {
    die("Error: personal_data.json no encontrado\n");
}

$jsonFile = file_get_contents('personal_data.json');
if (!$jsonFile) {
    die("Error: No se pudo leer el archivo\n");
}

$data = json_decode($jsonFile, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    die("Error al parsear JSON: " . json_last_error_msg() . "\n");
}

// Limpiar fechas inválidas en los registros
foreach ($data as &$record) {
    foreach (['date_entered', 'date_modified', 'fecha_nacimiento', 'fecha_contrato', 'fecha_cesante'] as $dateField) {
        if (
            isset($record[$dateField]) &&
            ($record[$dateField] === '0000-00-00 00:00:00' ||
                $record[$dateField] === '0000-00-00' ||
                empty($record[$dateField]))
        ) {
            $record[$dateField] = null; // Reemplazar valores inválidos con NULL
        }
    }
}

// Crear el contenido del seeder
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

            // Validar y limpiar fechas en los registros
            foreach (['date_entered', 'date_modified', 'fecha_nacimiento', 'fecha_contrato', 'fecha_cesante'] as \$dateField) {
                if (
                    isset(\$record[\$dateField]) &&
                    (\$record[\$dateField] === '0000-00-00 00:00:00' || 
                     \$record[\$dateField] === '0000-00-00' || 
                     empty(\$record[\$dateField]))
                ) {
                    \$record[\$dateField] = null;
                }
            }

            Personal::create(\$record);
        }
    }
}
EOD;

// Guardar el JSON limpio
$cleanedJsonPath = 'database/seeders/personal_data.json';
file_put_contents($cleanedJsonPath, json_encode($data, JSON_PRETTY_PRINT));

// Guardar el archivo del seeder
$seederPath = 'database/seeders/PersonalSeeder.php';
file_put_contents($seederPath, $seederContent);

echo "Seeder generado exitosamente en:\n";
echo "- Archivo JSON limpio: $cleanedJsonPath\n";
echo "- Seeder: $seederPath\n";
