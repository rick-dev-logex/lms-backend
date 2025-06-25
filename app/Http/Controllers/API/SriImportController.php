<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessSriTxt;
use App\Jobs\SyncEstadoContableJob;
use App\Models\SriRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

class SriImportController extends Controller
{
    /**
     * Sube el archivo al storage local y despacha el job de procesamiento.
     * Retorna JSON con la ruta relativa (jobPath).
     */
    public function uploadTxt(Request $request)
    {
        $relativePath = $request->file('file')->store('imports', 'local');

        ProcessSriTxt::dispatch(
            $relativePath,
            true,
            'sri-txt'
        );

        SyncEstadoContableJob::dispatch($relativePath);

        return response()->json([
            'success'   => true,
            'message'   => 'Importación iniciada en segundo plano.',
            'job_path'  => $relativePath,
        ]);
    }

    /**
     * Devuelve el progreso de importación.
     * JSON:
     * {
     *   countTotal: number,
     *   countProcessed: number,
     *   countSkipped: number,
     *   countErrors: number,
     *   countDone: number
     * }
     */
    public function status(string $path)
    {
        try {
            $fullPath = storage_path("app/{$path}");
            if (! File::exists($fullPath)) {
                return response()->json([
                    'countTotal'     => 0,
                    'countProcessed' => 0,
                    'countSkipped'   => 0,
                    'countErrors'    => 0,
                    'countDone'      => 0,
                ]);
            }

            // 1) Leemos líneas (sin cabecera ni vacías)
            $raw   = File::get($fullPath);
            $lines = array_filter(
                preg_split('/\r?\n/', trim($raw)),
                fn($l) => trim($l) !== '' && !str_starts_with(trim($l), 'RUC_EMISOR')
            );
            $total = count($lines);

            // 2) Extraemos claves de acceso del lote
            $claves = array_map(fn($l) => explode("\t", $l)[4] ?? null, $lines);
            $claves = array_filter($claves);

            // 3) Contamos sólo este lote (hoy) y diferenciamos estados
            $hoy    = now()->toDateString();
            $counts = SriRequest::where('raw_path', $path)
                ->whereIn('clave_acceso', $claves)
                ->whereDate('created_at', $hoy)
                ->selectRaw("SUM(status = 'processed' AND invoice_id IS NOT NULL)   as processed")
                ->selectRaw("SUM(status = 'skipped')                               as skipped")
                ->selectRaw("SUM(status = 'error')                                 as errors")
                ->first();

            $processed = (int) $counts->processed;
            $skipped   = (int) $counts->skipped;
            $errors    = (int) $counts->errors;
            $done      = $processed + $skipped + $errors;

            return response()->json([
                'countTotal'     => $total,
                'countProcessed' => $processed,
                'countSkipped'   => $skipped,
                'countErrors'    => $errors,
                'countDone'      => $done,
            ]);
        } catch (\Throwable $e) {
            Log::error("[status] Error obteniendo progreso de importación: {$e->getMessage()}", ['path' => $path]);
            // Siempre devolvemos un JSON 200 válido para que el frontend no muestre toasts de error
            return response()->json([
                'countTotal'     => 0,
                'countProcessed' => 0,
                'countSkipped'   => 0,
                'countErrors'    => 0,
                'countDone'      => 0,
            ]);
        }
    }
}
