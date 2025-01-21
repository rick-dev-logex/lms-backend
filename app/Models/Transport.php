<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Transport extends Model
{
    use HasFactory;

    protected $connection = 'onix'; // Conexión a onix
    protected $table = 'onix_vehiculos'; // Nombre de la tabla
}
