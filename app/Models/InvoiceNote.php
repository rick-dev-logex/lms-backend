<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InvoiceNote extends Model
{

    protected $fillable = [
        'invoice_id',
        'name',
        'description',
    ];

    protected static $logName = 'invoice_note';
    protected static $logFillable = true;
    protected static $logOnlyDirty = true;

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }
}
