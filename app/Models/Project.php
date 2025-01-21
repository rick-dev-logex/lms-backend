<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Project extends Model
{
    use HasFactory;

    protected $connection = 'onix'; // Usa la conexión a sistema_onix
    protected $table = 'onix_proyectos'; // Define el nombre de la tabla

    protected $fillable = ['proyecto', 'tipo'];

    public function accounts()
    {
        return $this->belongsTo(Account::class, 'project', 'proyecto');
    }

    public static function findByProject($project)
    {
        return static::where('proyecto', $project)->get();
    }
}
