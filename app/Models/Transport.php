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
    protected $fillable = ['placa', 'marca', 'modelo', 'anio', 'tipo', 'estado', 'proyecto'];
}
