<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class CajaChica extends Model
{
    use SoftDeletes;
    protected $table = 'caja_chica';
    protected $fillable = [
        'FECHA',
        'CODIGO',
        'DESCRIPCION',
        'SALDO',
        'CENTRO COSTO',
        'CUENTA',
        'NOMBRE DE CUENTA',
        'PROVEEDOR',
        'EMPRESA',
        'PROYECTO',
        'I_E',
        'MES SERVICIO',
        'TIPO',
        'ESTADO',
    ];

    // cast
    protected $casts = [
        'FECHA' => 'date',
        'MES SERVICIO' => 'date',
        'SALDO' => 'decimal:2',
    ];

    protected $hidden = [
        'deleted_at'
    ];
}
