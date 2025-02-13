<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

class UserProject extends Pivot
{
    protected $table = 'user_project';

    /**
     * Indicates if the IDs are auto-incrementing.
     *
     * @var bool
     */
    public $incrementing = true;

    protected $casts = [
        'project_id' => 'string',
    ];
}
