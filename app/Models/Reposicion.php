<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Database\Eloquent\Builder;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class Reposicion extends Model
{
    use HasApiTokens, Notifiable;

    // protected $connection = 'lms_backend';
    protected $table = 'reposiciones';

    protected $fillable = [
        'fecha_reposicion',
        'total_reposicion',
        'status',
        'project',
        'detail',
        'month',
        'when',
        'note',
        'attachment_url',
        'attachment_name'
    ];

    protected $casts = [
        'detail' => 'array',
        'fecha_reposicion' => 'datetime',
        'total_reposicion' => 'decimal:2',
        'created_at' => 'datetime',
        'updated_at' => 'datetime'
    ];

    // Atributos que siempre deben ocultarse en la serialización
    protected $hidden = [
        'updated_at'
    ];

    public function requests()
    {
        return Request::whereIn('unique_id', $this->getDetailIds());
    }

    public function requestsWithRelations()
    {
        return $this->requests()->with(['account:id,name']);
    }

    protected function getDetailIds(): array
    {
        return is_array($this->detail)
            ? collect($this->detail)->flatten()->unique()->values()->toArray()
            : json_decode($this->detail) ?? [];
    }

    public function calculateTotal(): float
    {
        return (float) $this->requests()->sum('amount');
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

        // Antes de guardar, asegurarse de que el total sea correcto
        static::saving(function ($reposicion) {
            if ($reposicion->isDirty('detail')) {
                $reposicion->total_reposicion = $reposicion->calculateTotal();
            }
        });

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
