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

    protected string $relativePath;
    protected bool   $ignoreHeader;
    protected string $sourceTag;

    public function __construct(string $relativePath, bool $ignoreHeader = true, string $sourceTag = 'sri-txt')
    {
        $this->relativePath = $relativePath;
        $this->ignoreHeader = $ignoreHeader;
        $this->sourceTag    = $sourceTag;
    }

    public function handle(InvoiceImportService $importService): void
    {
        $fullPath = storage_path("app/{$this->relativePath}");
        $lines    = preg_split('/\r?\n/', trim(File::get($fullPath)));

        if ($this->ignoreHeader && str_starts_with($lines[0] ?? '', 'RUC_EMISOR')) {
            array_shift($lines);
        }

        // 1) Pre‐cargamos en memoria todas las claves que ya existen
        $claves = array_filter(array_map(fn($l) => (str_getcsv(trim($l), "\t"))[4] ?? null, $lines));
        $existentes = Invoice::whereIn('clave_acceso', $claves)
            ->pluck('clave_acceso')
            ->all();

        // 2) Procesamos línea a línea
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $parts     = str_getcsv($line, "\t");
            $clave     = $parts[4] ?? null;
            $fechaAuth = $parts[5] ?? null;  // la penúltima columna

            // 2.1) Si ya existe, lo marcamos skipped y saltamos
            if ($clave && in_array($clave, $existentes, true)) {
                Log::info("Factura duplicada ignorada: {$clave}");
                SriRequest::create([
                    'raw_path'     => $this->relativePath,
                    'raw_line'     => $line,
                    'clave_acceso' => $clave,
                    'status'       => 'skipped',
                ]);
                continue;
            }

            // 2.2) Si no existe, delegamos a importFromTxt (ahora con fechaAuth)
            try {
                $importService->importFromTxt(
                    $line,
                    basename($this->relativePath),
                    $this->sourceTag,
                    $this->relativePath,
                    $fechaAuth
                );
            } catch (Throwable $e) {
                Log::error("Error importando línea en Job", [
                    'line'    => $line,
                    'message' => $e->getMessage(),
                ]);
            }
        }
    }
}
