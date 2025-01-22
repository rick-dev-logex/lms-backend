<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Laravel\Sanctum\HasApiTokens;

class Position extends Model
{
    use HasFactory;
    use HasApiTokens;

    protected $fillable = [
        'name',
        'permissions',
        'description',
        'status',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'permissions' => 'array',
        'status' => 'boolean',
    ];

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
