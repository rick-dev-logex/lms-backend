<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Laravel\Sanctum\HasApiTokens;

class Account extends Model
{
    use HasFactory;
    use HasApiTokens;

    // protected $connection = 'lms_backend';
    protected $table = 'accounts';
    protected $fillable = [
        'name',
        'account_number',
        'account_type',
        'account_status',
        'account_affects',
        'generates_income'
    ];

    // Relación con personal de Onix a través del proyecto
    public function projectPersonnel()
    {
        return $this->hasMany(Personal::class, 'proyecto', 'project');
    }

    // Relación con las solicitudes
    public function requests()
    {
        return $this->hasMany(Request::class);
    }

    // Scope para filtrar por tipo
    public function scopeOfType($query, $type)
    {
        return $query->where('type', $type);
    }
}
