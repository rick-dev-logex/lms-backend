<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReposicionProyecto extends Model
{
    protected $table = 'reposicion_proyecto';

    protected $fillable = [
        'reposicion_id',
        'project',
    ];

    public function reposicion()
    {
        return $this->belongsTo(Reposicion::class);
    }
}