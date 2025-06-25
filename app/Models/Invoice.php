<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Invoice extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'clave_acceso',
        'ruc_emisor',
        'razon_social_emisor',
        'nombre_comercial_emisor',
        'identificacion_comprador',
        'razon_social_comprador',
        'direccion_comprador',
        'tipo_identificacion_comprador',
        'estab',
        'pto_emi',
        'secuencial',
        'invoice_serial',
        'ambiente',
        'fecha_emision',
        'fecha_autorizacion',
        'total_sin_impuestos',
        'importe_total',
        'iva',
        'propina',
        'moneda',
        'forma_pago',
        'placa',
        'project',
        'notas',
        'observacion',
        'contabilizado',
        'cuenta_contable',
        'centro_costo',
        'proveedor_latinium',
        'nota_latinium',
        'estado',
        'estado_latinium',
        'numero_asiento',
        'numero_transferencia',
        'correo_pago',
        'purchase_order_id',
        'empresa',
        'xml_path',
        'pdf_path',
        'mes',
        'cod_doc',
    ];

    protected $casts = [
        'fecha_emision' => 'datetime',
        'fecha_autorizacion' => 'datetime',
        'total_sin_impuestos' => 'decimal:2',
        'importe_total' => 'decimal:2',
        'iva' => 'decimal:2',
        'propina' => 'decimal:2',
        'mes' => 'integer',
    ];

    protected static $logName = 'invoice';
    protected static $logFillable = true;
    protected static $logOnlyDirty = true;

    public function purchaseOrder()
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function details()
    {
        return $this->hasOne(InvoiceDetail::class);
    }

    public function notes()
    {
        return $this->hasMany(InvoiceNote::class);
    }
}
