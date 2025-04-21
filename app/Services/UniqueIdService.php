<?php

namespace App\Services;

use App\Models\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class UniqueIdService
{
    // Definir constantes para los prefijos
    private const PREFIX_EXPENSE = 'G-';
    private const PREFIX_INCOME = 'I-';
    private const PREFIX_DISCOUNT = 'D-';
    private const PREFIX_LOAN = 'P-';
    private const PREFIX_DEFAULT = 'S-';

    // Cache TTL en segundos (1 hora)
    private const CACHE_TTL = 3600;

    /**
     * Genera un ID único para solicitudes que no exista en la base de datos
     * 
     * @param string $type Tipo de solicitud ('expense', 'discount', 'income')
     * @return string ID único generado
     */
    public function generateUniqueRequestId($type)
    {
        $prefix = $this->getPrefixForType($type);

        // Obtener el último ID generado directamente de la base de datos
        // No usamos caché para garantizar que siempre obtenemos el valor más actualizado
        $nextNumber = $this->getLastIdNumberFromDB($prefix) + 1;

        // Generar el ID con ceros a la izquierda
        $uniqueId = $prefix . str_pad($nextNumber, 5, '0', STR_PAD_LEFT);

        // Verificar si existe (por seguridad, aunque no debería pasar)
        if ($this->idExists($uniqueId)) {
            Log::warning("ID duplicado detectado: {$uniqueId}. Recalculando último ID desde la base de datos.");

            // Si existe, volvemos a consultar la base de datos (posible inserción concurrente)
            $nextNumber = $this->getLastIdNumberFromDB($prefix) + 1;
            $uniqueId = $prefix . str_pad($nextNumber, 5, '0', STR_PAD_LEFT);

            // Si aún existe, incrementamos hasta encontrar uno disponible
            while ($this->idExists($uniqueId)) {
                $nextNumber++;
                $uniqueId = $prefix . str_pad($nextNumber, 5, '0', STR_PAD_LEFT);
                Log::info("Probando ID siguiente: {$uniqueId}");
            }
        }

        return $uniqueId;
    }

    /**
     * Obtiene el último número usado para un prefijo específico directamente de la base de datos
     * 
     * @param string $prefix Prefijo del ID
     * @return int Último número usado
     */
    private function getLastIdNumberFromDB(string $prefix): int
    {
        $prefixLength = strlen($prefix);

        $maxIdQuery = DB::table('requests')
            ->where('unique_id', 'like', $prefix . '%')
            ->selectRaw('MAX(CAST(SUBSTRING(unique_id, ' . ($prefixLength + 1) . ') AS UNSIGNED)) as max_id')
            ->first();

        return ($maxIdQuery && $maxIdQuery->max_id) ? $maxIdQuery->max_id : 0;
    }

    /**
     * Verifica si un ID ya existe en la base de datos
     * 
     * @param string $uniqueId ID a verificar
     * @return bool True si existe, False si no
     */
    private function idExists(string $uniqueId): bool
    {
        return Request::where('unique_id', $uniqueId)->exists();
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
                return self::PREFIX_EXPENSE;
            case 'income':
                return self::PREFIX_INCOME;
            case 'discount':
                return self::PREFIX_DISCOUNT;
            case 'loan':
                return self::PREFIX_LOAN;
            default:
                return self::PREFIX_DEFAULT;
        }
    }
}
