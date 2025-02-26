<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Laravel\Sanctum\HasApiTokens;

class Permission extends Model
{
    use HasFactory;
    use HasApiTokens;

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->setConnection(env('APP_ENV') === 'production' ? 'lms_backend' : 'mysql');
    }
    protected $table = 'permissions';

    protected $fillable = ['name'];

    public function users()
    {
        return $this->belongsToMany(User::class);
    }
}
