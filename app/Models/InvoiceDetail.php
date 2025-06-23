<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InvoiceDetail extends Model
{
    protected $table = 'invoice_details';

    protected $fillable = [
        'invoice_id',
        'codigo_principal',
        'codigo_auxiliar',
        'descripcion',
        'cantidad',
        'precio_unitario',
        'descuento',
        'precio_total_sin_impuesto',
        'cod_impuesto',
        'cod_porcentaje',
        'tarifa',
        'base_imponible_impuestos',
        'valor_impuestos',
    ];

    /**
     * Factura asociada.
     */
    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }
}
