<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;

class PurchaseOrder extends Model
{
    use SoftDeletes, LogsActivity;

    protected $fillable = [
        'codigo',
        'estado',
        'observacion',
    ];

    protected static $logName = 'purchase_order';
    protected static $logFillable = true;
    protected static $logOnlyDirty = true;

    public function invoices()
    {
        return $this->hasMany(Invoice::class);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('purchase_order')
            ->logFillable()
            ->logOnlyDirty();
    }
}
