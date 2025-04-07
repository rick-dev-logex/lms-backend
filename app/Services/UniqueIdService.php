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

        // Buscar el último unique_id con ese prefijo
        $lastRequest = Request::where('unique_id', 'like', $prefix . '%')
            ->orderBy('unique_id', 'desc')
            ->first();

        if ($lastRequest) {
            // Extraer el número del último unique_id (ejemplo: "D-00008" -> 8)
            $lastNumber = (int) substr($lastRequest->unique_id, strlen($prefix));
            $nextNumber = $lastNumber + 1;
        } else {
            // Si no hay registros previos, empezar en 1
            $nextNumber = 1;
        }

        // Formatear el nuevo unique_id con ceros a la izquierda (ejemplo: "D-00009")
        $uniqueId = $prefix . str_pad($nextNumber, 5, '0', STR_PAD_LEFT);

        // Verificar si ya existe (por seguridad) y ajustar si es necesario
        while (Request::where('unique_id', $uniqueId)->exists()) {
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
