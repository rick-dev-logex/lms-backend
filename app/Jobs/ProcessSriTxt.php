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

            $parts = str_getcsv($line, "\t");
            $clave = $parts[4] ?? null;

            $sr = SriRequest::where('raw_path', $this->relativePath)
                ->where('raw_line', $line)
                ->first();

            if (Invoice::where('clave_acceso', $clave)->exists()) {
                Log::info("[{$this->sourceTag}] Duplicate skipped: {$clave}");
                $sr->update(['status' => 'skipped']);
            } else {
                try {
                    $importService->importFromTxt(
                        $line,
                        basename($this->relativePath),
                        $this->sourceTag,
                        $this->relativePath,
                        $line
                    );
                } catch (Throwable $e) {
                    Log::error("[{$this->sourceTag}] Error importing line: {$e->getMessage()}", ['line' => $line]);
                }
                $sr->update(['status' => 'processed']);
            }
        }
    }
}
