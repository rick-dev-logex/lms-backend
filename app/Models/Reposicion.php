<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Laravel\Sanctum\HasApiTokens;

class Reposicion extends Model
{
    use HasApiTokens;

    protected $connection = 'lms_backend';
    protected $table = 'reposiciones';

    protected $fillable = [
        'fecha_reposicion',
        'total_reposicion',
        'status',
        'project',
        'detail',
        'month',
        'when',
        'note'
    ];

    protected $casts = [
        'detail' => 'array',
        'fecha_reposicion' => 'date',
        'total_reposicion' => 'decimal:2'
    ];

    // Obtiene todas las solicitudes relacionadas a esta reposición
    public function requests()
    {
        return $this->hasMany(Request::class, 'unique_id', 'detail');
    }

    // Método para obtener el total calculado de todas las solicitudes
    public function calculateTotal()
    {
        return $this->requests->sum('amount');
    }

    // Scope para filtrar por proyecto
    public function scopeByProject($query, $project)
    {
        return $query->where('project', $project);
    }

    // Scope para filtrar por mes
    public function scopeByMonth($query, $month)
    {
        return $query->where('month', $month);
    }

    // Scope para filtrar por estado
    public function scopeByStatus($query, $status)
    {
        return $query->where('status', $status);
    }
}
