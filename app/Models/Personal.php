<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class Personal extends Authenticatable
{
    use HasApiTokens, Notifiable, HasUuids;

    protected $connection = 'sistema_onix';
    protected $table = 'onix_personal';
    public $incrementing = false; // Indica que no es un número incremental
    protected $keyType = 'string'; // Indica que el ID es un string, no un entero
    public $timestamps = false;

    protected $fillable = [
        'nombres',
        'proyecto',
        'estado_personal',
        'deleted',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    // Scope para filtrar solo personal activo
    public function scopeActive($query)
    {
        return $query->where('estado_personal', 'activo');
    }
    // Relación con las solicitudes donde la persona es responsable
    public function responsibleFor()
    {
        return $this->hasMany(Request::class, 'responsible_id', 'nombre_completo');
    }
    // Relación con las cuentas a través del proyecto
    public function accounts()
    {
        return $this->hasMany(Account::class, 'project', 'proyecto');
    }

    public function area()
    {
        return $this->belongsTo(Area::class, 'area_id', 'id');
    }
}
