<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Laravel\Sanctum\HasApiTokens;

class Permission extends Model
{
    use HasFactory;
    use HasApiTokens;

    protected $connection = 'lms_local';
    // protected $connection = 'lms_backend';
    protected $table = 'permissions';

    protected $fillable = ['name'];

    public function roles()
    {
        return $this->belongsToMany(Role::class, 'permission_role');
    }

    public function users()
    {
        return $this->belongsToMany(User::class, 'permission_user');
    }
}
