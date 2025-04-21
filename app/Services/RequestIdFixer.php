<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Models\Request;
use Exception;

class RequestIdFixer
{
    /**
     * Asignar reposicion_id a todos los requests que estén en estado in_reposition, paid o rejected
     * y que no tengan reposicion_id asignado.
     *
     * @return array Información sobre registros actualizados
     */
    public function assignMissingRepositionIds()
    {
        $stats = [
            'total_processed' => 0,
            'updated' => 0,
            'errors' => 0,
        ];

        // Estados que deberían tener reposicion_id
        $validStates = ['in_reposition', 'paid', 'rejected'];

        // Obtener requests sin reposicion_id que deberían tenerlo
        $requests = DB::table('requests')
            ->whereIn('status', $validStates)
            ->whereNull('reposicion_id')
            ->get();

        $stats['total_processed'] = count($requests);
        Log::info("Encontrados {$stats['total_processed']} requests sin reposicion_id asignado.");

        if ($stats['total_processed'] === 0) {
            return $stats;
        }

        // Procesar cada request
        foreach ($requests as $request) {
            try {
                // Buscar en qué reposición está incluido este request
                $reposicion = DB::table('reposiciones')
                    ->where('detail', 'like', '%' . $request->unique_id . '%')
                    ->first();

                if ($reposicion) {
                    // Actualizar el request con el reposicion_id correspondiente
                    DB::table('requests')
                        ->where('id', $request->id)
                        ->update(['reposicion_id' => $reposicion->id]);

                    Log::info("Request ID {$request->id} (unique_id: {$request->unique_id}) actualizado con reposicion_id: {$reposicion->id}");
                    $stats['updated']++;
                } else {
                    Log::warning("No se encontró reposición para el request ID {$request->id} (unique_id: {$request->unique_id})");
                }
            } catch (Exception $e) {
                Log::error("Error procesando request ID {$request->id}: " . $e->getMessage());
                $stats['errors']++;
            }
        }

        return $stats;
    }

    /**
     * Obtener mapeo de IDs problemáticos de descuentos a nuevos IDs secuenciales.
     *
     * @return array Mapeo de IDs antiguos a nuevos
     */
    public function mapDiscountIds()
    {
        $threshold = 99999; // Umbral para considerar un ID "gigante"

        // Obtener el último ID válido (menor al umbral)
        $lastValidId = DB::table('requests')
            ->where('unique_id', 'like', 'D-%')
            ->whereRaw('CAST(SUBSTRING(unique_id, 3) AS UNSIGNED) < ?', [$threshold])
            ->selectRaw('MAX(CAST(SUBSTRING(unique_id, 3) AS UNSIGNED)) AS number')
            ->first();

        // Definimos el próximo ID secuencial a usar
        $nextNumber = ($lastValidId && $lastValidId->number) ? ($lastValidId->number + 1) : 1;

        // Obtener todos los IDs problemáticos ordenados por creación para mantener secuencia cronológica
        $problematicIds = DB::table('requests')
            ->where('unique_id', 'like', 'D-%')
            ->whereRaw('CAST(SUBSTRING(unique_id, 3) AS UNSIGNED) >= ?', [$threshold])
            ->orderBy('created_at')
            ->get(['id', 'unique_id', 'created_at']);

        $totalProblematic = count($problematicIds);
        Log::info("Se encontraron {$totalProblematic} IDs problemáticos para reasignar. Próximo número secuencial: {$nextNumber}");

        $idMap = [];
        foreach ($problematicIds as $request) {
            $oldId = $request->unique_id;
            $newId = 'D-' . str_pad($nextNumber, 5, '0', STR_PAD_LEFT);
            $idMap[$oldId] = [
                'new_id' => $newId,
                'request_id' => $request->id,
                'created_at' => $request->created_at
            ];
            $nextNumber++;
        }

        return $idMap;
    }

    /**
     * Actualizar unique_id en la tabla requests para IDs de descuentos problemáticos.
     *
     * @return array Estadísticas de la actualización
     */
    public function updateDiscountIds()
    {
        $idMap = $this->mapDiscountIds();
        $stats = [
            'total' => count($idMap),
            'updated_requests' => 0,
            'updated_reposiciones' => 0,
            'errors' => 0
        ];

        if (empty($idMap)) {
            Log::info("No se encontraron IDs problemáticos para actualizar en requests.");
            return $stats;
        }

        try {
            DB::beginTransaction();

            // 1. Actualizar IDs en la tabla requests
            foreach ($idMap as $oldId => $data) {
                $newId = $data['new_id'];
                $requestId = $data['request_id'];

                $affected = DB::table('requests')
                    ->where('id', $requestId)
                    ->update(['unique_id' => $newId]);

                if ($affected) {
                    Log::info("Actualizado en requests: {$oldId} -> {$newId}, request_id: {$requestId}");
                    $stats['updated_requests']++;
                } else {
                    Log::warning("No se pudo actualizar el request ID {$requestId}: {$oldId} -> {$newId}");
                }
            }

            // 2. Actualizar referencias en reposiciones
            $reposiciones = DB::table('reposiciones')
                ->whereRaw("detail REGEXP '.*D-[0-9]{6,}.*'")
                ->get(['id', 'detail']);

            foreach ($reposiciones as $reposicion) {
                $detail = $reposicion->detail;
                $modified = false;

                // Validar que el detail sea un JSON válido
                $detailArray = json_decode($detail, true);
                if (!is_array($detailArray)) {
                    Log::warning("Formato JSON inválido en reposicion ID {$reposicion->id}: {$detail}");
                    continue;
                }

                // Reemplazar cada ID antiguo con el nuevo
                $updatedDetail = array_map(function ($id) use ($idMap, &$modified) {
                    if (isset($idMap[$id])) {
                        $modified = true;
                        return $idMap[$id]['new_id'];
                    }
                    return $id;
                }, $detailArray);

                // Actualizar solo si hubo cambios
                if ($modified) {
                    DB::table('reposiciones')
                        ->where('id', $reposicion->id)
                        ->update(['detail' => json_encode($updatedDetail)]);

                    Log::info("Actualizada reposicion ID {$reposicion->id} con nuevos IDs");
                    $stats['updated_reposiciones']++;
                }
            }

            DB::commit();
            Log::info("Transacción completada exitosamente: Actualizados {$stats['updated_requests']} requests y {$stats['updated_reposiciones']} reposiciones");
        } catch (Exception $e) {
            DB::rollBack();
            Log::error("Error durante la actualización de IDs: " . $e->getMessage());
            $stats['errors']++;
            throw $e;
        }

        return $stats;
    }

    /**
     * Verificar la integridad después de las actualizaciones.
     *
     * @return array Resultados del diagnóstico
     */
    public function verifyIntegrity()
    {
        $results = [
            'abnormal_ids' => 0,
            'missing_reposicion_ids' => 0,
            'inconsistencies' => []
        ];

        // Verificar IDs anormalmente grandes
        $abnormalIds = DB::table('requests')
            ->where('unique_id', 'like', 'D-%')
            ->whereRaw('CAST(SUBSTRING(unique_id, 3) AS UNSIGNED) >= 99999')
            ->get(['id', 'unique_id']);

        $results['abnormal_ids'] = count($abnormalIds);

        if ($results['abnormal_ids'] > 0) {
            Log::warning("Se encontraron {$results['abnormal_ids']} IDs anormalmente grandes después de la corrección");
            foreach ($abnormalIds as $request) {
                $results['inconsistencies'][] = "ID anormal: {$request->unique_id} (request_id: {$request->id})";
            }
        }

        // Verificar requests sin reposicion_id que deberían tenerlo
        $missingReposicionIds = DB::table('requests')
            ->whereIn('status', ['in_reposition', 'paid', 'rejected'])
            ->whereNull('reposicion_id')
            ->get(['id', 'unique_id', 'status']);

        $results['missing_reposicion_ids'] = count($missingReposicionIds);

        if ($results['missing_reposicion_ids'] > 0) {
            Log::warning("Se encontraron {$results['missing_reposicion_ids']} requests sin reposicion_id después de la corrección");
            foreach ($missingReposicionIds as $request) {
                $results['inconsistencies'][] = "Falta reposicion_id: {$request->unique_id} (request_id: {$request->id}, status: {$request->status})";
            }
        }

        // Verificar inconsistencias entre requests y reposiciones
        $reposiciones = DB::table('reposiciones')->get(['id', 'detail']);

        foreach ($reposiciones as $reposicion) {
            $detailArray = json_decode($reposicion->detail, true);

            if (!is_array($detailArray)) {
                $results['inconsistencies'][] = "Reposicion ID {$reposicion->id} tiene detail con formato JSON inválido";
                continue;
            }

            foreach ($detailArray as $uniqueId) {
                // Verificar que existe el request
                $request = DB::table('requests')
                    ->where('unique_id', $uniqueId)
                    ->first();

                if (!$request) {
                    $results['inconsistencies'][] = "Reposicion ID {$reposicion->id} referencia al unique_id {$uniqueId} que no existe";
                    continue;
                }

                // Verificar que el reposicion_id coincide
                if ($request->reposicion_id != $reposicion->id) {
                    $results['inconsistencies'][] = "Request {$uniqueId} tiene reposicion_id {$request->reposicion_id} pero está en la reposicion {$reposicion->id}";
                }
            }
        }

        return $results;
    }

    /**
     * Ejecutar el proceso completo de corrección.
     *
     * @return array Resultados del proceso
     */
    public function fixAll()
    {
        $results = [
            'reposicion_assignment' => [],
            'id_normalization' => [],
            'verification' => []
        ];

        try {
            Log::info("Iniciando proceso completo de corrección de IDs y asignación de reposicion_id.");

            // Paso 1: Asignar reposicion_id faltantes
            Log::info("Paso 1: Asignando reposicion_id faltantes...");
            $results['reposicion_assignment'] = $this->assignMissingRepositionIds();

            // Paso 2: Normalizar IDs de descuentos
            Log::info("Paso 2: Normalizando IDs de descuentos...");
            $results['id_normalization'] = $this->updateDiscountIds();

            // Paso 3: Verificar integridad
            Log::info("Paso 3: Verificando integridad después de las correcciones...");
            $results['verification'] = $this->verifyIntegrity();

            Log::info("Proceso completo de corrección finalizado.");

            return $results;
        } catch (Exception $e) {
            Log::error("Error durante el proceso completo de corrección: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Generar un unique_id único para una solicitud.
     *
     * @param string $type Tipo de solicitud (discount, expense, income, loan)
     * @return string
     */
    public function generateUniqueRequestId($type)
    {
        $prefix = $this->getPrefixForType($type);

        // Consultar el número más alto usado, ignorando valores anormalmente grandes
        $maxIdQuery = DB::table('requests')
            ->where('unique_id', 'like', $prefix . '-%')
            ->whereRaw('CAST(SUBSTRING(unique_id, 3) AS UNSIGNED) < 99999')
            ->selectRaw('MAX(CAST(SUBSTRING(unique_id, 3) AS UNSIGNED)) as max_id')
            ->first();

        $nextNumber = ($maxIdQuery && $maxIdQuery->max_id) ? ($maxIdQuery->max_id + 1) : 1;

        // Generar el ID
        $uniqueId = $prefix . '-' . str_pad($nextNumber, 5, '0', STR_PAD_LEFT);

        // Seguridad: verificar duplicados
        $attempts = 0;
        $maxAttempts = 10;
        while (Request::where('unique_id', $uniqueId)->exists() && $attempts < $maxAttempts) {
            Log::warning("ID duplicado detectado: {$uniqueId}. Intentando con el siguiente valor.");
            $nextNumber++;
            $uniqueId = $prefix . '-' . str_pad($nextNumber, 5, '0', STR_PAD_LEFT);
            $attempts++;
        }

        if ($attempts >= $maxAttempts) {
            Log::error("No se pudo generar un ID único para el prefijo {$prefix} después de {$maxAttempts} intentos.");
            throw new Exception("No se pudo generar un ID único. Por favor, revisa los datos.");
        }

        Log::info("ID generado: {$uniqueId} para tipo {$type}");
        return $uniqueId;
    }

    /**
     * Obtener el prefijo según el tipo de solicitud.
     *
     * @param string $type
     * @return string
     */
    protected function getPrefixForType($type)
    {
        $prefixes = [
            'discount' => 'D',
            'expense' => 'G',
            'income' => 'I',
            'loan' => 'P',
        ];

        return $prefixes[$type] ?? throw new Exception("Tipo de solicitud inválido: {$type}");
    }
}
