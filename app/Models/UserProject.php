<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

class UserProject extends Pivot
{
    protected $table = 'user_project';
    protected $connection = 'lms_backend';

    public $incrementing = true;

    protected $casts = [
        'project_id' => 'string',
    ];
}
