<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Laravel\Sanctum\HasApiTokens;

class Area extends Model
{
    use HasApiTokens;

    protected $fillable = ['name', 'description'];

    public function projects()
    {
        return $this->hasMany(Project::class);
    }

    public function requests()
    {
        return $this->hasMany(Request::class);
    }

    public function transports()
    {
        return $this->hasMany(Transport::class);
    }

    public function accounts()
    {
        return $this->hasMany(Account::class);
    }

    public function personals()
    {
        return $this->hasMany(Personal::class);
    }
}
