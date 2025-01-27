<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Laravel\Sanctum\HasApiTokens;

class Request extends Model
{
    use HasFactory;
    use HasApiTokens;

    protected $connection = 'lms_backend'; // lms_backend

    protected $fillable = [
        'type',
        'personnel_type',
        'project',
        'request_date',
        'invoice_number',
        'account_id',
        'amount',
        'note',
        'unique_id',
        'attachment_path',
        'responsible_id',
        'transport_id',
        'status'
    ];

    protected $casts = [
        'request_date' => 'date',
        'amount' => 'decimal:2'
    ];

    // Obtiene la reposición a la que pertenece esta solicitud
    public function reposicion()
    {
        return $this->belongsTo(Reposicion::class, 'unique_id', 'detail');
    }

    public function account()
    {
        return $this->belongsTo(Account::class);
    }

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function responsible()
    {
        return $this->belongsTo(User::class, 'responsible_id');
    }

    public function transport()
    {
        return $this->belongsTo(Transport::class);
    }

    // Scope para filtrar solicitudes pendientes de reposición
    public function scopePendingReposition($query)
    {
        return $query->whereNull('reposicion_id')->where('status', 'approved');
    }

    // Scope para filtrar por proyecto
    public function scopeByProject($query, $project)
    {
        return $query->where('project', $project);
    }
}
