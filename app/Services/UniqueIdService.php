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
     * Obtiene el próximo número base para un tipo de solicitud
     *
     * @param string $type
     * @return int
     */
    public function getNextBaseNumber(string $type): int
    {
        $prefix = $this->getPrefixForType($type);
        return $this->getLastIdNumberFromDB($prefix) + 1;
    }

    /**
     * Genera un ID único para solicitudes que no exista en la base de datos
     * 
     * @param string $type Tipo de solicitud ('expense', 'discount', 'income')
     * @param int|null $offset Opcional: incremento adicional para generar múltiples IDs en lote
     * @return string ID único generado
     */
    public function generateUniqueRequestId($type, ?int $offset = null)
    {
        $prefix = $this->getPrefixForType($type);

        // Obtener el último número base
        $lastNumber = $this->getLastIdNumberFromDB($prefix);

        // Definir el siguiente número a partir de lastNumber o offset
        $nextNumber = $offset !== null && $offset > 0 ? $offset : $lastNumber + 1;

        $uniqueId = $prefix . str_pad($nextNumber, 5, '0', STR_PAD_LEFT);

        // Si ya existe, buscar siguiente libre incrementando hasta encontrar uno
        if ($this->idExists($uniqueId)) {
            Log::warning("ID duplicado detectado: {$uniqueId}. Buscando siguiente ID libre.");

            // Iniciar desde el mayor entre lastNumber + 1 y offset (si existe)
            $nextNumber = max($lastNumber + 1, $offset ?? 0);

            do {
                $uniqueId = $prefix . str_pad($nextNumber, 5, '0', STR_PAD_LEFT);
                $nextNumber++;
                Log::info("Probando ID siguiente: {$uniqueId}");
            } while ($this->idExists($uniqueId));
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
