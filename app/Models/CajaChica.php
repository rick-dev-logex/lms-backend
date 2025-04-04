<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CajaChica extends Model
{
    protected $fillable = [
        'fecha',
        'codigo',
        'descripcion',
        'saldo',
        'centro_costo',
        'cuenta',
        'nombre_de_cuenta',
        'proveedor',
        'empresa',
        'proyecto',
        'i_e',
        'mes_servicio',
        'tipo',
        'estado',
    ];

    // cast
    protected $casts = [
        'fecha' => 'date',
        'mes_servicio' => 'date',
        'saldo' => 'decimal:2',
    ];
}
