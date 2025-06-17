<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\Builder;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Log;

class Reposicion extends Model
{
    use Notifiable, SoftDeletes, HasFactory;

    protected $table = 'reposiciones';

    protected $fillable = [
        'fecha_reposicion',
        'total_reposicion',
        'status',
        'project',
        'month',
        'when',
        'note',
        'attachment_url',
        'attachment_name'
    ];

    protected $casts = [
        'fecha_reposicion' => 'datetime',
        'total_reposicion' => 'decimal:2',
        'created_at' => 'datetime',
        'updated_at' => 'datetime'
    ];

    protected $hidden = [
        'updated_at'
    ];

    // Relación uno a muchos con requests
    public function requests()
    {
        return $this->hasMany(Request::class, 'reposicion_id');
    }

    // Relación con la tabla pivote de proyectos para optimizar consultas
    public function proyectos()
    {
        return $this->hasMany(ReposicionProyecto::class);
    }

    // Relación con eager loading para optimizar consultas
    public function requestsWithRelations()
    {
        return $this->hasMany(Request::class, 'reposicion_id')->with(['account:id,name']);
    }

    // Calcular total basado en la suma de requests relacionados
    public function calculateTotal(): float
    {
        return (float) $this->requests()->sum('amount');
    }

    // Obtener los unique_ids de las requests (para compatibilidad con código existente)
    public function getDetailAttribute()
    {
        return $this->requests()->pluck('unique_id')->toArray();
    }

    // Scopes optimizados
    public function scopeByProject(Builder $query, string $project): Builder
    {
        return $query->where('project', $project);
    }

    public function scopeByMonth(Builder $query, string $month): Builder
    {
        return $query->where('month', $month);
    }

    public function scopeByStatus(Builder $query, string $status): Builder
    {
        return $query->where('status', $status);
    }

    public function scopeSearch(Builder $query, string $term): Builder
    {
        return $query->where(function ($q) use ($term) {
            $q->where('project', 'like', "%{$term}%")
                ->orWhere('total_reposicion', 'like', "%{$term}%")
                ->orWhere('note', 'like', "%{$term}%");
        });
    }

    public function scopeInDateRange(Builder $query, Carbon $start, Carbon $end = null): Builder
    {
        if ($end === null) {
            return $query->whereDate('fecha_reposicion', $start);
        }
        return $query->whereBetween('fecha_reposicion', [$start, $end]);
    }

    protected static function boot()
    {
        parent::boot();

        // Antes de eliminar, eliminar el archivo de Google Cloud Storage si existe
        static::deleting(function ($reposicion) {
            if ($reposicion->attachment_name) {
                try {
                    $storage = new \Google\Cloud\Storage\StorageClient([
                        'keyFilePath' => env('GOOGLE_CLOUD_KEY_FILE')
                    ]);
                    $bucket = $storage->bucket(env('GOOGLE_CLOUD_BUCKET'));
                    $object = $bucket->object($reposicion->attachment_name);
                    if ($object->exists()) {
                        $object->delete();
                    }
                } catch (\Exception $e) {
                    Log::error('Error deleting file from GCS: ' . $e->getMessage());
                }
            }
        });
    }
}
