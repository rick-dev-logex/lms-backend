<?php

namespace App\Services;

use App\Models\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class UniqueIdService
{
    /**
     * Genera un ID único para solicitudes que no exista en la base de datos
     * 
     * @param string $type Tipo de solicitud ('expense', 'discount', 'income')
     * @return string ID único generado
     */
    public function generateUniqueRequestId($type)
    {
        // Obtener el prefijo según el tipo
        $prefix = $this->getPrefixForType($type);

        // Obtener todos los IDs con este prefijo
        $existingIds = Request::where('unique_id', 'like', $prefix . '%')
            ->pluck('unique_id')
            ->toArray();

        // Si no hay IDs existentes, empezar desde 1
        if (empty($existingIds)) {
            return $prefix . '00001';
        }

        // Encontrar el número más alto actualmente en uso
        $highestNumber = 0;
        $prefixLength = strlen($prefix);

        foreach ($existingIds as $id) {
            $currentNumber = (int) substr($id, $prefixLength);
            $highestNumber = max($highestNumber, $currentNumber);
        }

        // Comenzar con el siguiente número disponible
        $nextNumber = $highestNumber + 1;

        // Formatear el ID con ceros a la izquierda
        $uniqueId = $prefix . str_pad($nextNumber, 5, '0', STR_PAD_LEFT);

        // Verificar si el ID generado ya existe y buscar otro disponible si es necesario
        while (in_array($uniqueId, $existingIds)) {
            Log::warning("ID duplicado detectado: {$uniqueId}. Intentando con el siguiente valor.");
            $nextNumber++;
            $uniqueId = $prefix . str_pad($nextNumber, 5, '0', STR_PAD_LEFT);
        }

        // Verificación final de seguridad directamente contra la base de datos
        $maxAttempts = 100; // Límite de seguridad para evitar bucles infinitos
        $attempts = 0;

        while (Request::where('unique_id', $uniqueId)->exists() && $attempts < $maxAttempts) {
            Log::warning("ID duplicado detectado a pesar de la verificación: {$uniqueId}. Incrementando al siguiente valor.");
            $nextNumber++;
            $uniqueId = $prefix . str_pad($nextNumber, 5, '0', STR_PAD_LEFT);
            $attempts++;
        }

        if ($attempts >= $maxAttempts) {
            Log::error("No se pudo generar un ID único después de {$maxAttempts} intentos para el tipo {$type}");
            throw new \Exception("No se pudo generar un ID único después de múltiples intentos");
        }

        Log::info("ID único generado exitosamente: {$uniqueId} para tipo {$type} después de {$attempts} verificaciones adicionales");

        return $uniqueId;
    }

    /**
     * Obtiene el prefijo correspondiente al tipo de solicitud
     * 
     * @param string $type Tipo de solicitud
     * @return string Prefijo del ID
     */
    private function getPrefixForType(string $type): string
    {
        switch (strtolower($type)) {
            case 'expense':
                return 'G-';
            case 'income':
                return 'I-';
            case 'discount':
                return 'D-';
            case 'loan':
                return 'P-';
            default:
                return 'S-'; // Genérico para otros casos | S de solicitud
        }
    }
}
