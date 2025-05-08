<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SriDocument extends Model
{
    protected $fillable = [
        'clave_acceso',
        'ruc_emisor',
        'razon_social_emisor',
        'tipo_comprobante',
        'serie_comprobante',
        'nombre_xml',
        'nombre_pdf',
        'gcs_path_xml',
        'gcs_path_pdf',
        'fecha_autorizacion',
        'fecha_emision',
    ];
}
