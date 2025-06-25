<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessSriTxt;
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

        // Contamos líneas reales (sin cabecera ni vacías)
        $lines = array_filter(
            preg_split('/\r?\n/', File::get($fullPath)),
            fn($l) => trim($l) !== '' && ! str_starts_with($l, 'RUC_EMISOR')
        );
        $total = count($lines);

        // Obtenemos conteos desde BD
        $counts = SriRequest::where('raw_path', $path)
            ->selectRaw("SUM(status = 'processed') as processed")
            ->selectRaw("SUM(status = 'skipped')   as skipped")
            ->selectRaw("SUM(status = 'error')     as errors")
            ->first();

        $processed = (int) optional($counts)->processed;
        $skipped   = (int) optional($counts)->skipped;
        $errors    = (int) optional($counts)->errors;
        $done      = $processed + $skipped + $errors;

        return response()->json([
            'countTotal'     => $total,
            'countProcessed' => $processed,
            'countSkipped'   => $skipped,
            'countErrors'    => $errors,
            'countDone'      => $done,
        ]);
    }
}
