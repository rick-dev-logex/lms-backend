<?php

namespace App\Observers;

use App\Models\Invoice;
use Illuminate\Support\Facades\DB;

class InvoiceObserver
{
    /**
     * Se ejecuta **antes** de crear o actualizar el modelo.
     */
    public function saving(Invoice $invoice)
    {
        // Sólo si ya tiene todos los campos necesarios:
        if (
            $invoice->project &&
            $invoice->cuenta_contable &&
            $invoice->observacion &&
            $invoice->secuencial &&
            $invoice->centro_costo &&
            $invoice->proveedor_latinium
        ) {
            // 1) Obtener el código del proyecto (CodSubproyecto) a partir del nombre
            $projectCode = DB::connection('latinium')
                ->table('dbo.Proyecto_rs AS pr')
                ->join('dbo.SubProyecto AS sp', 'pr.idSubProyecto', '=', 'sp.idSubproyecto')
                ->where('sp.Nombre', $invoice->project)
                ->value('pr.CodSubproyecto');

            // 2) Si no existe código (por algún motivo), usar el valor original
            $codeOrName = $projectCode ?: $invoice->project;

            // 3) Armar la nota con el código en vez del nombre
            $invoice->nota_latinium = implode(' / ', [
                $codeOrName,
                $invoice->cuenta_contable,
                $invoice->observacion,
                $invoice->secuencial,
                $invoice->centro_costo,
                $invoice->proveedor_latinium,
            ]);
        }
    }
}
