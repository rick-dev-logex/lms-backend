<?php

namespace App\Jobs;

use App\Models\Invoice;
use App\Models\SriRequest;
use App\Services\InvoiceImportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessSriTxt implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private string $relativePath = '';
    private bool $ignoreHeader = true;
    private string $sourceTag = 'default';
    /**
     * Tiempo máximo (en segundos) que Laravel esperará antes de matar el job.
     * 0 para timeout infinito, o en un valor mayor (p.ej. 600 = 10 min).
     */
    public $timeout = 600;

    /**
     * Cuántas veces volver a intentar un job fallido si lanza excepción.
     */
    public $tries = 1;

    public function __construct(string $relativePath, bool $ignoreHeader = true, string $sourceTag = 'default')
    {
        $this->relativePath = $relativePath;
        $this->ignoreHeader = $ignoreHeader;
        $this->sourceTag    = $sourceTag;
    }

    public function handle(InvoiceImportService $importService): void
    {
        // 1) Intentamos levantar el servicio de importación
        try {
            /** @var \App\Services\InvoiceImportService $importService */
            $importService = app(\App\Services\InvoiceImportService::class);
        } catch (\Throwable $e) {
            Log::error("[{$this->sourceTag}] No se pudo inicializar InvoiceImportService: " . $e->getMessage());
            // Marcamos todo el lote como error y salimos
            SriRequest::where('raw_path', $this->relativePath)
                ->update([
                    'status'        => 'error',
                    'error_message' => 'Servicio SRI no disponible: ' . $e->getMessage(),
                ]);
            return;
        }

        $fullPath = storage_path("app/{$this->relativePath}");
        $content  = File::get($fullPath);
        $lines    = preg_split('/\r?\n/', trim($content));

        if ($this->ignoreHeader && isset($lines[0]) && str_starts_with($lines[0], 'RUC_EMISOR')) {
            array_shift($lines);
        }

        // 1) Creamos un SriRequest PENDING por cada línea del TXT (incluso duplicados)
        foreach ($lines as $rawLine) {
            $line = trim($rawLine);
            if ($line === '') continue;

            // 1) Detectar codificación (solo para debugging):
            // $enc = mb_detect_encoding($line, ['UTF-8','ISO-8859-1','Windows-1252'], true);
            // Log::info("Detected encoding: $enc");

            // 2) Convertir CP1252 (Windows) → UTF-8 y descartar inválidos
            $clean = mb_convert_encoding($line, 'UTF-8', 'Windows-1252');
            $clean = iconv('UTF-8', 'UTF-8//IGNORE', $clean);

            $parts = str_getcsv($clean, "\t");
            $clave = $parts[4] ?? null;

            try {
                SriRequest::updateOrCreate(
                    ['raw_path'     => $this->relativePath, 'clave_acceso' => $clave],
                    ['raw_line'     => $clean, 'status'       => 'pending']
                );
            } catch (\Throwable $e) {
                Log::error("[{$this->sourceTag}] SriRequest insert failed: " . $e->getMessage(), ['line' => $clean]);
                continue;
            }
        }



        // 2) Recorremos otra vez y actualizamos SOLO los PENDING de cada línea
        foreach ($lines as $rawLine) {
            $line = trim($rawLine);
            if ($line === '') {
                continue;
            }

            $parts = str_getcsv($line, "\t");
            $clave = $parts[4] ?? null;

            // Tomamos todos los SriRequest PENDING para esta línea
            $pending = SriRequest::where('raw_path', $this->relativePath)
                ->where('raw_line', $line)
                ->where('status', 'pending')
                ->get();
            if ($pending->isEmpty()) {
                continue;
            }

            // 2.a) Si ya existe la factura, marcamos todos esos PENDING como skipped
            $existing = Invoice::where('clave_acceso', $clave)->first();
            if ($existing) {
                Log::info("[{$this->sourceTag}] Duplicate skipped: {$clave}");
                foreach ($pending as $sr) {
                    $sr->update([
                        'status'     => 'skipped',
                        'invoice_id' => $existing->id,
                    ]);
                }
                continue;
            }

            // 2.b) Si no existe, intentamos importar y marcamos SOLO el primer PENDING
            try {
                $invoice = $importService->importFromTxt(
                    $line,
                    basename($this->relativePath),
                    $this->sourceTag,
                    $this->relativePath,
                    $line
                );
                $pending->first()->update([
                    'status'     => 'processed',
                    'invoice_id' => $invoice->id,
                ]);
            } catch (\Throwable $e) {
                Log::error("[{$this->sourceTag}] Error importing line: {$e->getMessage()}", ['line' => $line]);
                foreach ($pending as $sr) {
                    $sr->update([
                        'status'        => 'error',
                        'error_message' => $e->getMessage(),
                    ]);
                }
            }
        }
        Artisan::call('update:estado-contable');
    }
}
