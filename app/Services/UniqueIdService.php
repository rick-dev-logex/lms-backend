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
    // En el servicio UniqueIdService.php
    public function generateUniqueRequestId(string $type): string
    {
        // Determinar el prefijo según el tipo
        $prefix = $this->getPrefixForType($type);

        // Consultar el último ID con el prefijo específico
        $lastRecord = Request::where('type', $type)
            ->where('unique_id', 'like', $prefix . '%')
            ->orderByRaw('CAST(SUBSTRING(unique_id, ' . (strlen($prefix) + 1) . ') AS UNSIGNED) DESC')
            ->first();

        // Obtener el próximo ID numérico
        $nextId = 1;
        if ($lastRecord) {
            // Extraer el número del ID usando expresión regular
            if (preg_match('/' . preg_quote($prefix, '/') . '(\d+)/', $lastRecord->unique_id, $matches)) {
                $nextId = (int)$matches[1] + 1;
            }
        }

        // Formatear el ID con ceros a la izquierda (5 dígitos)
        $uniqueId = sprintf('%s%05d', $prefix, $nextId);

        // IMPORTANTE: Verificación adicional para evitar duplicados
        // Bucle para incrementar hasta encontrar un ID no utilizado
        while (Request::where('unique_id', $uniqueId)->exists()) {
            $nextId++;
            $uniqueId = sprintf('%s%05d', $prefix, $nextId);
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
            default:
                return 'R-'; // Genérico para otros casos
        }
    }

    /**
     * Genera un ID de respaldo en caso de error
     * 
     * @param string $prefix Prefijo del ID
     * @return string ID único generado
     */
    private function generateFallbackId(string $prefix): string
    {
        // Generar un timestamp + aleatorio para asegurar unicidad
        $randomPart = time() . rand(1000, 9999);
        $uniqueId = $prefix . $randomPart;

        // Verificar que no exista (improbable pero posible)
        while (Request::where('unique_id', $uniqueId)->exists()) {
            $randomPart = time() . rand(1000, 9999);
            $uniqueId = $prefix . $randomPart;
        }

        return $uniqueId;
    }
}
