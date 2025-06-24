<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SriRequest extends Model
{
    protected $fillable = [
        'raw_path',
        'raw_line',
        'clave_acceso',
        'status',
        'error_message',
        'invoice_id',
    ];

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }
}
