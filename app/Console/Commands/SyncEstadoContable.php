<?php

namespace App\Console\Commands;

use App\Models\Invoice;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SyncEstadoContable extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'update:estado-contable';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Actualiza el estado contable de cada factura en la base de datos de LMS.';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Sincronizando estado contable con LATINIUM');

        // 1) Traemos sólo las pendientes
        $facturas = Invoice::where('contabilizado', 'PENDIENTE')->get();
        $this->info('Facturas pendientes: ' . $facturas->count());
        foreach ($facturas as $factura) {
            $this->line('Clave acceso: ' . $factura->clave_acceso);

            // 2) Elegimos la conexión correcta
            $connection = $factura->identificacion_comprador === '0992301066001'
                ? 'latinium_prebam'
                : 'latinium_sersupport';

            $this->line('Usando conexión: ' . $connection);
            
            // 3) Consultamos por AutFactura = clave_acceso
            $existeCompra = DB::connection($connection)
                ->table('Compra')
                ->where('AutFactura', $factura->clave_acceso)
                ->exists();

            $this->line('¿Compra existe? ' . ($existeCompra ? 'Sí' : 'No'));

            if ($existeCompra && $factura->contabilizado !== 'CONTABILIZADO') {
                $factura->update(['contabilizado' => 'CONTABILIZADO']);
                $this->info("Factura ID {$factura->id} actualizada a CONTABILIZADO");
            } else {
                $this->line("Sin cambios para factura ID {$factura->id}");
            }
        }

        $this->info('Sincronización contable completada.');
    }
}
