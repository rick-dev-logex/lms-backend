<?php

namespace App\Imports;

use App\Models\Request;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

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

    public function importExcelData(array $row) {}
}
