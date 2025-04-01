<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserAssignedProjects extends Model
{
    // protected $connection = 'lms_backend';
    protected $table = 'user_assigned_projects';

    protected $casts = [
        'projects' => 'array',
    ];

    protected $fillable = ['user_id', 'projects'];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
