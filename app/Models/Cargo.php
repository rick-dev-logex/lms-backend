<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Cargo extends Model
{
    /** @use HasFactory<\Database\Factories\CargoFactory> */
    use HasFactory;

    protected $fillable = [
        'cargo',
    ];

    //Relaciones 

    public function personals()
    {
        return $this->hasMany(Personal::class, 'cargo_id'); // Relación inversa: un cargo puede tener muchos personales
    }
}

// Para obtener el cargo de un personal:

// $personal = Personal::find(1);
// $cargo = $personal->cargo; // Obtiene el cargo asociado a este personal

// Para obtener todos los personales de un cargo:

// $cargo = Cargo::find(1);
// $personals = $cargo->personals; // Obtiene todos los personales asociados a este cargo