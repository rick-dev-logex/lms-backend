<?php

namespace App\Observers;

use App\Models\Invoice;
use Illuminate\Support\Facades\DB;

class InvoiceObserver
{
    /**
     * Se ejecuta antes de crear o actualizar el modelo para armar la nota Latinium.
     */
    public function saving(Invoice $invoice)
    {
        // Solo si vienen los datos obligatorios para la nota
        if (! (
            $invoice->project &&
            $invoice->cuenta_contable &&
            $invoice->observacion &&
            $invoice->secuencial &&
            $invoice->centro_costo &&
            $invoice->proveedor_latinium
        )) {
            return;
        }

        // Elegir conexión LATINIUM según el comprador
        $latConn = $invoice->identificacion_comprador === '0992301066001'
            ? 'latinium_prebam'
            : 'latinium_sersupport';

        // Obtener el código de proyecto (CodSubproyecto) a partir del nombre
        $projectCode = DB::connection($latConn)
            ->table('dbo.Proyecto_rs AS pr')
            ->join('dbo.SubProyecto AS sp', 'pr.idSubProyecto', '=', 'sp.idSubproyecto')
            ->where('sp.Nombre', $invoice->project)
            ->value('pr.CodSubproyecto');

        // Si no existe código, usar el nombre original
        $codeOrName = $projectCode ?: $invoice->project;

        // Armar y asignar la nota
        $invoice->nota_latinium = implode(' / ', [
            $codeOrName,
            $invoice->cuenta_contable,
            $invoice->observacion,
            $invoice->secuencial,
            $invoice->centro_costo,
            $invoice->proveedor_latinium,
        ]);
    }

    /**
     * Se ejecuta después de guardar el modelo para sincronizar el estado contable.
     */
    public function saved(Invoice $invoice)
    {
        // Elegir conexión LATINIUM según el comprador
        $latConn = $invoice->identificacion_comprador === '0992301066001'
            ? 'latinium_prebam'
            : 'latinium_sersupport';

        // Verificar existencia en Compra usando AutFactura = clave_acceso
        $exists = DB::connection($latConn)
            ->table('Compra')
            // ->where('AutFactura', $invoice->clave_acceso)
            ->where('Numero', $invoice->secuencial)
            ->exists();

        // Si existe asiento y aún no está marcado, lo actualizamos silenciosamente
        if ($exists && $invoice->contabilizado !== 'CONTABILIZADO') {
            $invoice->updateQuietly([
                'contabilizado'   => 'CONTABILIZADO',
                'estado_latinium' => 'contabilizado',
            ]);
        }
    }
}
