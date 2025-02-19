<?php

namespace App\Imports;

use App\Models\Request;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PDO;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\Exception as ReaderException;

class RequestsImport implements ToModel, WithHeadingRow
{
    public function model(array $row)
    {
        // Manejo del archivo adjunto
        $fileUrl = $row['attachment']; // La columna en el Excel para el archivo adjunto

        if (filter_var($fileUrl, FILTER_VALIDATE_URL)) {
            // Generar un nombre único para el archivo
            $fileName = Str::random(10) . '.' . pathinfo($fileUrl, PATHINFO_EXTENSION);

            // Descargar el archivo de la URL
            $fileContent = file_get_contents($fileUrl);

            // Guardar el archivo en el almacenamiento público
            $path = 'attachments/' . $fileName;
            Storage::disk('public')->put($path, $fileContent);
        } else {
            // Asignar una ruta predeterminada o manejar un archivo ya subido
            $fileName = 'default_attachment.pdf';
            $path = 'attachments/' . $fileName;
        }

        // Determinar el prefijo basado en el tipo (gasto o descuento)
        if ($row['type'] === 'expense' || $row['type'] === 'gasto') {
            $prefix = "G-";
        } else if ($row['type'] === 'discount' || $row['type'] === 'descuento') {
            $prefix = "D-";
        };

        // Obtener el último número para el tipo correspondiente
        $lastRequest = Request::where('unique_id', 'LIKE', "{$prefix}%")
            ->orderBy('unique_id', 'desc')
            ->first();

        // Extraer el número actual o inicializar en 1
        $lastNumber = $lastRequest ? (int)str_replace($prefix, '', $lastRequest->unique_id) : 0;
        $newNumber = $lastNumber + 1;

        // Construir el nuevo unique_id
        $uniqueId = $prefix . $newNumber;

        // Crear el nuevo modelo con los datos del archivo Excel
        return new Request([
            'unique_id' => $uniqueId, // ID generado dinámicamente
            'type' => $row['type'], // expense o discount
            'status' => 'pending', // Se asume como pendiente al importar
            'request_date' => $row['request_date'],
            'invoice_number' => $row['invoice_number'],
            'account_id' => $row['account_id'],
            'amount' => $row['amount'],
            'project_id' => $row['project_id'],
            'responsible_id' => $row['responsible_id'],
            'transport_id' => $row['transport_id'] ?? null, // Opcional
            'attachment_path' => $path, // Guardar la ruta del archivo adjunto
            'note' => $row['note'],
        ]);
    }

    //TODO: HACER QUE FUNCIONE ESTE HANDLEIMPORT YA QUE NO SE ESTA UTILIZANDO
    public function handleImport($filepath): void
    {
        // Aquí deberías convertir el excel a csv

        // Insertar datos
        $pdo = DB::connection()->getPdo();
        $pdo->setAttribute(PDO::MYSQL_ATTR_LOCAL_INFILE, true);
        $filepath = str_replace('\\', '/', $filepath);

        $query = <<<SQL
LOAD DATA LOCAL INFILE '$filepath'
INTO TABLE requests
FIELDS TERMINATED BY ','
ENCLOSED BY '"'
LINES TERMINATED BY '\n'
IGNORE 1 LINES
SET
    unique_id = @col2,
    type = @col3,
    status = @col4,
    request_date = @col5,
    invoice_number = @col6,
    account_id = @col7,
    amount = @col8,
    project_id = @col9,
    responsible_id = @col10,
    transport_id = @col1,
    attachment_path = @col2,
    note = @col3,
    created_at = NOW(),
    updated_at = NOW()
SQL;

        $pdo->exec($query);
    }

    public function convertExcelToCsv(string $filepath): string
    {
        // Si el archivo ya es CSV, se retorna directamente
        $extension = strtolower(pathinfo($filepath, PATHINFO_EXTENSION));
        if ($extension === 'csv') {
            return $filepath;
        }

        try {
            // Carga el archivo Excel (xls o xlsx)
            $spreadsheet = IOFactory::load($filepath);
        } catch (ReaderException $e) {
            throw new \Exception('Error al leer el archivo Excel: ' . $e->getMessage());
        }

        // Crea el escritor CSV
        $writer = IOFactory::createWriter($spreadsheet, 'Csv');
        // Configuraciones opcionales para el CSV
        $writer->setDelimiter(',');
        $writer->setEnclosure('"');
        $writer->setLineEnding("\n");
        $writer->setSheetIndex(0); // Usamos la primera hoja

        // Define la ruta de destino del CSV (reemplazamos la extensión por .csv)
        $csvFilePath = preg_replace('/\.(xls|xlsx)$/i', '.csv', $filepath);

        try {
            // Guarda el archivo CSV
            $writer->save($csvFilePath);
        } catch (\Exception $e) {
            throw new \Exception('Error al guardar el archivo CSV: ' . $e->getMessage());
        }

        return $csvFilePath;
    }
}
