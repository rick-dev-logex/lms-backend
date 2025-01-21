<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Request extends Model
{
    use HasFactory;

    protected $fillable = [
        'unique_id',
        'type',
        'status',
        'request_date',
        'invoice_number',
        'account_id',
        'amount',
        'project_id',
        'responsible_id',
        'transport_id',
        'attachment_path',
        'note',
    ];

    public function account()
    {
        return $this->belongsTo(Account::class);
    }

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function responsible()
    {
        return $this->belongsTo(User::class, 'responsible_id');
    }

    public function transport()
    {
        return $this->belongsTo(Transport::class);
    }
}
