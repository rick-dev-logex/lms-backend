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

        // 1) Creamos un SriRequest PENDING por cada línea del TXT (incluso duplicados)
        foreach ($lines as $rawLine) {
            $line = trim($rawLine);
            if ($line === '') {
                continue;
            }
            $parts = str_getcsv($line, "\t");
            $clave = $parts[4] ?? null;
            SriRequest::create([
                'raw_path'     => $this->relativePath,
                'raw_line'     => $line,
                'clave_acceso' => $clave,
                'status'       => 'pending',
            ]);
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
    }
}
