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
        $prefix = $this->getPrefixForType($type);
        $prefixLength = strlen($prefix);

        // Consultar directamente el número más alto usado
        $maxIdQuery = DB::table('requests')
            ->where('unique_id', 'like', $prefix . '%')
            ->selectRaw('MAX(CAST(SUBSTRING(unique_id, ' . ($prefixLength + 1) . ') AS UNSIGNED)) as max_id')
            ->first();

        $nextNumber = ($maxIdQuery && $maxIdQuery->max_id) ? ($maxIdQuery->max_id + 1) : 1;

        // Saltar el rango problemático
        if ($nextNumber >= 470 && $nextNumber <= 480) {
            Log::warning("Saltando rango problemático cerca de {$prefix}00471. Siguiente ID normal sería: {$prefix}" . str_pad($nextNumber, 5, '0', STR_PAD_LEFT));
            $nextNumber = 500;
        }

        // Generar el ID
        $uniqueId = $prefix . str_pad($nextNumber, 5, '0', STR_PAD_LEFT);

        // Seguridad adicional: asegurarse de que no exista en la base de datos
        while (Request::where('unique_id', $uniqueId)->exists()) {
            Log::warning("ID duplicado detectado: {$uniqueId}. Intentando con el siguiente valor.");
            $nextNumber++;
            $uniqueId = $prefix . str_pad($nextNumber, 5, '0', STR_PAD_LEFT);
        }

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
