<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Notifications\Notifiable;

class Request extends Model
{
    use HasFactory, Notifiable, SoftDeletes;

    protected $dates = ['deleted_at'];
    protected $fillable = [
        'type',
        'personnel_type',
        'project',
        'request_date',
        'month',
        'when',
        'invoice_number',
        'account_id',
        'amount',
        'note',
        'unique_id',
        'responsible_id',
        'cedula_responsable',
        'vehicle_plate',
        'vehicle_number',
        'status',
        'reposicion_id',
        'created_by',
        'updated_by'
    ];

    protected $casts = [
        'request_date' => 'date',
        'amount' => 'decimal:2',
        'created_at' => 'datetime',
        'updated_at' => 'datetime'
    ];

    protected $with = ['account:id,name'];
    protected $hidden = ['deleted_at'];

    // Relación muchos a uno con reposicion
    public function reposicion()
    {
        return $this->belongsTo(Reposicion::class, 'reposicion_id');
    }

    public function account()
    {
        return $this->belongsTo(Account::class)->select(['id', 'name']);
    }

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function responsible()
    {
        return $this->belongsTo(User::class, 'responsible_id')
            ->select(['id', 'nombre_completo']);
    }

    public function transport()
    {
        return $this->belongsTo(Transport::class)
            ->select(['id', 'name']);
    }

    // Scopes optimizados
    public function scopePendingReposition($query): Builder
    {
        return $query->whereNull('reposicion_id')
            ->where('status', 'approved');
    }

    public function scopeByProject($query, $project): Builder
    {
        return $query->where('project', $project);
    }

    public function scopeByStatus($query, $status): Builder
    {
        return $query->where('status', $status);
    }

    public function scopeByType($query, $type): Builder
    {
        return $query->where('type', $type);
    }

    public function scopeByPersonnelType($query, $type): Builder
    {
        return $query->where('personnel_type', $type);
    }

    public function scopeByDate($query, $startDate, $endDate = null): Builder
    {
        if ($endDate) {
            return $query->whereBetween('request_date', [$startDate, $endDate]);
        }
        return $query->whereDate('request_date', $startDate);
    }

    public function scopeSearch($query, $term): Builder
    {
        return $query->where(function ($q) use ($term) {
            $q->where('unique_id', 'like', "%{$term}%")
                ->orWhere('project', 'like', "%{$term}%")
                ->orWhere('invoice_number', 'like', "%{$term}%")
                ->orWhere('amount', 'like', "%{$term}%")
                ->orWhereHas('account', function ($q) use ($term) {
                    $q->where('name', 'like', "%{$term}%");
                });
        });
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class);
    }
}