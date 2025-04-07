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
    public function generateUniqueRequestId(string $type): string
    {
        try {
            $prefix = $this->getPrefixForType($type);

            // Usar una transacción para evitar problemas de concurrencia
            return DB::transaction(function () use ($prefix, $type) {
                // Consultar el último ID con el prefijo específico
                $lastRecord = Request::where('type', $type)
                    ->where('unique_id', 'like', $prefix . '%')
                    ->orderByRaw('CAST(SUBSTRING(unique_id, ' . (strlen($prefix) + 1) . ') AS UNSIGNED) DESC')
                    ->lockForUpdate() // Bloqueo para evitar concurrencia
                    ->first();

                $nextId = 1;
                if ($lastRecord && $lastRecord->unique_id) {
                    if (preg_match('/' . preg_quote($prefix, '/') . '(\d+)/', $lastRecord->unique_id, $matches)) {
                        $nextId = (int)$matches[1] + 1;
                    } else {
                        Log::warning('Formato de unique_id inválido encontrado: ' . $lastRecord->unique_id);
                    }
                }

                // Generar el ID inicial
                $uniqueId = sprintf('%s%05d', $prefix, $nextId);

                // Verificar duplicados y ajustar si es necesario
                while (Request::where('unique_id', $uniqueId)->exists()) {
                    $nextId++;
                    $uniqueId = sprintf('%s%05d', $prefix, $nextId);
                }

                return $uniqueId;
            });
        } catch (\Exception $e) {
            Log::error('Error generando unique_id: ' . $e->getMessage());
            // Usar un ID de respaldo en caso de fallo crítico
            return $this->generateFallbackId($prefix);
        }
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
        $randomPart = time() . rand(1000, 9999);
        $uniqueId = $prefix . $randomPart;

        while (Request::where('unique_id', $uniqueId)->exists()) {
            $randomPart = time() . rand(1000, 9999);
            $uniqueId = $prefix . $randomPart;
        }

        Log::info('Generado unique_id de respaldo: ' . $uniqueId);
        return $uniqueId;
    }
}
