<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Laravel\Sanctum\HasApiTokens;

class Project extends Model
{
    use HasFactory;
    use HasApiTokens;

    protected $connection = 'sistema_onix'; // Usa la conexión a sistema_onix
    protected $table = 'onix_proyectos'; // Define el nombre de la tabla
    public $incrementing = false; // Indica que no es un número incremental
    protected $keyType = 'string'; // Indica que el ID es un string, no un entero

    protected $fillable = ['proyecto', 'tipo'];

    public function accounts()
    {
        return $this->belongsTo(Account::class, 'project', 'proyecto');
    }

    public static function findByProject($project)
    {
        return static::where('proyecto', $project)->get();
    }

    /**
     * Los usuarios asignados al proyecto
     */
    public function users()
    {
        return $this->belongsToMany(User::class, 'user_assigned_projects', 'project_id', 'user_id')
            ->using(UserAssignedProjects::class);
    }

    /**
     * Scope para proyectos activos
     */
    public function scopeActive($query)
    {
        return $query->where('deleted', '0')->where('activo', '1');
    }
}
