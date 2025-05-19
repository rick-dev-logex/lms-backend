<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SriDocument extends Model
{
    use HasFactory;

    protected $fillable = [
        'clave_acceso',
        'ruc_emisor',
        'razon_social_emisor',
        'tipo_comprobante',
        'serie_comprobante',
        'nombre_xml',
        'nombre_pdf',
        'xml_path_identifier',
        'pdf_path_identifier',
        'fecha_autorizacion',
        'fecha_emision',
        'valor_sin_impuestos',
        'iva',
        'importe_total',
        'identificacion_receptor',
    ];
    protected $dates = [
        'fecha_autorizacion',
        'fecha_emision',
    ];
}
