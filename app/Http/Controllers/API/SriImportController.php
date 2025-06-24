<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessSriTxt;
use App\Models\SriRequest;
use App\Services\InvoiceImportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Throwable;

class SriImportController extends Controller
{
    public function __construct(private InvoiceImportService $importService) {}

    public function uploadTxt(Request $request)
    {
        // permitir tiempo indefinido
        set_time_limit(0);
        // aumentar memoria si hace falta
        ini_set('memory_limit', '512M');

        $request->validate([
            'file' => 'required|file|mimes:txt,text/plain',
        ]);

        // 1) Guarda el TXT en storage/app/imports y captura el path relativo
        $relativePath = $request->file('file')->store('imports', 'local');

        // 2) Despacha el job pasándole el path RELATIVO
        ProcessSriTxt::dispatch(
            $relativePath,   // ej: "imports/0992301066001_Recibidos.txt"
            true,            // ignore-header
            'sri-txt'
        );

        // 3) Responder de inmediato
        return response()->json([
            'success'   => true,
            'message'   => 'Importación iniciada en segundo plano.',
            'job_path'  => $relativePath,
        ]);
    }

    public function status(string $path)
    {
        $fullPath = storage_path("app/{$path}");
        if (! File::exists($fullPath)) {
            return response()->json(['countTotal' => 0, 'countDone' => 0]);
        }

        $lines = array_filter(
            preg_split('/\r?\n/', File::get($fullPath), -1, PREG_SPLIT_NO_EMPTY),
            fn(string $l) => trim($l) !== '' && ! str_starts_with($l, 'RUC_EMISOR')
        );
        $total = count($lines);

        // Ahora incluimos skipped en el conteo de “done”
        $done = SriRequest::where('raw_path', $path)
            ->whereIn('status', ['processed', 'error', 'skipped'])
            ->count();

        return response()->json(compact('total', 'done'));
    }
}
