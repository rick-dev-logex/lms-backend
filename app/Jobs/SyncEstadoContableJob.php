<?php

namespace App\Jobs;

use App\Models\Invoice;
use App\Models\SriRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

class SyncEstadoContableJob implements ShouldQueue
{
    use Dispatchable, Queueable, SerializesModels;

    /**
     * Ruta relativa del lote de importación (raw_path en SriRequest).
     * @var string
     */
    public string $relativePath;

    /**
     * Número de reintentos si el job falla.
     * @var int
     */
    public int $tries = 1;

    /**
     * Timeout en segundos para este job.
     * @var int
     */
    public int $timeout = 300;

    /**
     * Constructor.
     *
     * @param string $relativePath
     */
    public function __construct(string $relativePath)
    {
        $this->relativePath = $relativePath;
    }

    /**
     * Ejecuta la sincronización de estado contable para las facturas importadas en este lote.
     */
    public function handle(): void
    {
        // 1) Obtener todos los invoice_id asociados a este lote
        $invoiceIds = SriRequest::query()
            ->where('raw_path', $this->relativePath)
            ->whereNotNull('invoice_id')
            ->pluck('invoice_id')
            ->unique()
            ->filter();

        // 2) Para cada factura, verificar en 'Compra' y actualizar si es necesario
        foreach ($invoiceIds as $id) {
            $invoice = Invoice::find($id);
            if (! $invoice) {
                continue;
            }

            // Determinar conexión LATINIUM según el comprador
            $latConn = $invoice->identificacion_comprador === '0992301066001'
                ? 'latinium_prebam'
                : 'latinium_sersupport';

            // Verificar existencia en Compra (AutFactura = clave_acceso)
            $exists = DB::connection($latConn)
                ->table('Compra')
                ->where('AutFactura', $invoice->clave_acceso)
                ->exists();

            // Si encuentra asiento y no está marcado, actualizar silenciosamente
            if ($exists && $invoice->contabilizado !== 'CONTABILIZADO') {
                $invoice->updateQuietly([
                    'contabilizado'     => 'CONTABILIZADO',
                    'estado_latinium'   => 'contabilizado',
                ]);
            }
        }
    }
}
