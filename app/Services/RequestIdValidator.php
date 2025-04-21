<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RequestIdValidator
{
    /**
     * Validar los datos post-corrección de IDs de descuentos.
     *
     * @return array Resultados de la validación
     */
    public function validateDiscountIds()
    {
        $results = [
            'discount_count' => [],
            'reposiciones_issues' => [],
            'reposicion_id_mismatches' => [],
            'orphaned_ids' => [],
        ];

        try {
            Log::info("Iniciando validación de IDs de descuentos post-corrección.");

            // 1. Contar descuentos en requests
            $results['discount_count'] = $this->countDiscounts();

            // 2. Validar reposiciones.detail contra requests.unique_id
            $results['reposiciones_issues'] = $this->validateReposicionesDetail();

            // 3. Verificar relación reposicion_id vs detail
            $results['reposicion_id_mismatches'] = $this->validateReposicionIdRelation();

            // 4. Buscar IDs huérfanos en reposiciones.detail
            $results['orphaned_ids'] = $this->findOrphanedIds();

            Log::info("Validación de IDs de descuentos completada.");
        } catch (\Exception $e) {
            Log::error("Error durante la validación de IDs: " . $e->getMessage());
            $results['error'] = $e->getMessage();
        }

        return $results;
    }

    /**
     * Contar descuentos en la tabla requests.
     *
     * @return array
     */
    protected function countDiscounts()
    {
        $total = DB::table('requests')
            ->where('unique_id', 'like', 'D-%')
            ->count();

        $active = DB::table('requests')
            ->where('unique_id', 'like', 'D-%')
            ->whereNull('deleted_at')
            ->count();

        $deleted = DB::table('requests')
            ->where('unique_id', 'like', 'D-%')
            ->whereNotNull('deleted_at')
            ->count();

        $maxId = DB::table('requests')
            ->where('unique_id', 'like', 'D-%')
            ->selectRaw('MAX(CAST(SUBSTRING(unique_id, 3) AS UNSIGNED)) as max_number')
            ->first()->max_number;

        $results = [
            'total' => $total,
            'active' => $active,
            'deleted' => $deleted,
            'max_id' => $maxId ? "D-" . str_pad($maxId, 5, '0', STR_PAD_LEFT) : null,
        ];

        Log::info("Conteo de descuentos: " . json_encode($results));
        return $results;
    }

    /**
     * Validar que los IDs en reposiciones.detail existan en requests.
     *
     * @return array
     */
    protected function validateReposicionesDetail()
    {
        $issues = [];
        $reposiciones = DB::table('reposiciones')
            ->where('detail', 'like', '%D-%')
            ->get();

        foreach ($reposiciones as $reposicion) {
            $detail = json_decode($reposicion->detail, true);
            if (!is_array($detail)) {
                $issues[] = [
                    'reposicion_id' => $reposicion->id,
                    'issue' => 'Formato JSON inválido',
                    'detail' => $reposicion->detail,
                ];
                continue;
            }

            foreach ($detail as $id) {
                if (!preg_match('/^D-\d+$/', $id)) {
                    $issues[] = [
                        'reposicion_id' => $reposicion->id,
                        'issue' => "ID inválido: {$id}",
                        'detail' => $reposicion->detail,
                    ];
                    continue;
                }

                $exists = DB::table('requests')
                    ->where('unique_id', $id)
                    ->exists();

                if (!$exists) {
                    $issues[] = [
                        'reposicion_id' => $reposicion->id,
                        'issue' => "ID no encontrado en requests: {$id}",
                        'detail' => $reposicion->detail,
                    ];
                }
            }
        }

        if (!empty($issues)) {
            Log::warning("Problemas encontrados en reposiciones.detail: " . json_encode($issues));
        } else {
            Log::info("Todos los IDs en reposiciones.detail existen en requests.");
        }

        return $issues;
    }

    /**
     * Verificar que los reposicion_id en requests coincidan con reposiciones.detail.
     *
     * @return array
     */
    protected function validateReposicionIdRelation()
    {
        $mismatches = [];
        $requests = DB::table('requests')
            ->where('unique_id', 'like', 'D-%')
            ->whereNotNull('reposicion_id')
            ->select('id', 'unique_id', 'reposicion_id')
            ->get();

        foreach ($requests as $request) {
            $reposicion = DB::table('reposiciones')
                ->where('id', $request->reposicion_id)
                ->first();

            if (!$reposicion) {
                $mismatches[] = [
                    'request_id' => $request->id,
                    'unique_id' => $request->unique_id,
                    'reposicion_id' => $request->reposicion_id,
                    'issue' => 'Reposición no encontrada',
                ];
                continue;
            }

            $detail = json_decode($reposicion->detail, true);
            if (!is_array($detail)) {
                $mismatches[] = [
                    'request_id' => $request->id,
                    'unique_id' => $request->unique_id,
                    'reposicion_id' => $request->reposicion_id,
                    'issue' => 'Formato JSON inválido en reposicion',
                    'detail' => $reposicion->detail,
                ];
                continue;
            }

            if (!in_array($request->unique_id, $detail)) {
                $mismatches[] = [
                    'request_id' => $request->id,
                    'unique_id' => $request->unique_id,
                    'reposicion_id' => $request->reposicion_id,
                    'issue' => 'unique_id no encontrado en reposiciones.detail',
                    'detail' => $reposicion->detail,
                ];
            }
        }

        if (!empty($mismatches)) {
            Log::warning("Inconsistencias en la relación reposicion_id: " . json_encode($mismatches));
        } else {
            Log::info("Todas las relaciones reposicion_id son consistentes.");
        }

        return $mismatches;
    }

    /**
     * Buscar IDs en reposiciones.detail que no existen en requests.
     *
     * @return array
     */
    protected function findOrphanedIds()
    {
        $orphaned = [];
        $reposiciones = DB::table('reposiciones')
            ->where('detail', 'like', '%D-%')
            ->get();

        foreach ($reposiciones as $reposicion) {
            $detail = json_decode($reposicion->detail, true);
            if (!is_array($detail)) {
                continue;
            }

            foreach ($detail as $id) {
                if (!preg_match('/^D-\d+$/', $id)) {
                    continue;
                }

                $exists = DB::table('requests')
                    ->where('unique_id', $id)
                    ->exists();

                if (!$exists) {
                    $orphaned[] = [
                        'reposicion_id' => $reposicion->id,
                        'unique_id' => $id,
                        'detail' => $reposicion->detail,
                    ];
                }
            }
        }

        if (!empty($orphaned)) {
            Log::warning("IDs huérfanos encontrados en reposiciones.detail: " . json_encode($orphaned));
        } else {
            Log::info("No se encontraron IDs huérfanos en reposiciones.detail.");
        }

        return $orphaned;
    }
}
