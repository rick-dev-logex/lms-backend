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
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessSriTxt implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private string $relativePath = '';
    private bool $ignoreHeader = true;
    private string $sourceTag = 'default';

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

        // Pre-crear registros pending
        foreach ($lines as $rawLine) {
            $line = trim($rawLine);
            if ($line === '') continue;

            $parts = str_getcsv($line, "\t");
            $clave = $parts[4] ?? null;
            SriRequest::updateOrCreate(
                ['raw_path' => $this->relativePath, 'raw_line' => $line],
                ['clave_acceso' => $clave, 'status' => 'pending']
            );
        }

        // Procesar cada línea y actualizar el estado
        foreach ($lines as $rawLine) {
            $line = trim($rawLine);
            if ($line === '') continue;

            $clave = str_getcsv($line, "\t")[4] ?? null;
            $sr    = SriRequest::firstWhere([
                'raw_path' => $this->relativePath,
                'raw_line' => $line,
            ]);

            // 1) Si ya existe la factura → la marcamos como skipped y saltamos
            $existing = Invoice::where('clave_acceso', $clave)->first();
            if ($existing) {
                Log::info("[{$this->sourceTag}] Factura duplicada omitida: {$clave}");
                $sr->update([
                    'status'     => 'skipped',
                    'invoice_id' => $existing->id,
                ]);
                continue;
            }

            // 2) Intentar importar; al éxito guardo invoice_id, al fallo marco error
            try {
                $invoice = $importService->importFromTxt(
                    $line,
                    basename($this->relativePath),
                    $this->sourceTag,
                    $this->relativePath,
                    $line
                );
                $sr->update([
                    'status'     => 'processed',
                    'invoice_id' => $invoice->id,
                ]);
            } catch (\Throwable $e) {
                Log::error("[{$this->sourceTag}] Error importing line: {$e->getMessage()}", ['line' => $line]);
                $sr->update([
                    'status'        => 'error',
                    'error_message' => $e->getMessage(),
                ]);
            }
        }
    }
}
