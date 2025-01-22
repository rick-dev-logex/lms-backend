<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Laravel\Sanctum\HasApiTokens;

class Transport extends Model
{
    use HasFactory;
    use HasApiTokens;

    protected $connection = 'onix'; // Conexión a onix
    protected $table = 'onix_vehiculos'; // Nombre de la tabla
    public $incrementing = false; // Indica que no es un número incremental
    protected $keyType = 'string'; // Indica que el ID es un string, no un entero
    protected $fillable = ['placa', 'marca', 'modelo', 'anio', 'tipo', 'estado', 'proyecto'];
}
