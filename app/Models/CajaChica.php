<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CajaChica extends Model
{
    protected $table = 'caja_chica';
    protected $fillable = [
        'FECHA',
        'CODIGO',
        'DESCRIPCION',
        'SALDO',
        'CENTRO_COSTO',
        'CUENTA',
        'NOMBRE_DE_CUENTA',
        'PROVEEDOR',
        'EMPRESA',
        'PROYECTO',
        'I_E',
        'MES_SERVICIO',
        'TIPO',
        'ESTADO',
    ];

    // cast
    protected $casts = [
        'FECHA' => 'date',
        'MES_SERVICIO' => 'date',
        'SALDO' => 'decimal:2',
    ];
}
