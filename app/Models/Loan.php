<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class Loan extends Model
{
    use HasApiTokens, Notifiable;

    protected $fillable = [
        'loan_date',
        'type',
        'account_id',
        'account_name',
        'amount',
        'project',
        'file_path',
        'note',
        'installments',
        'responsible_id',
        'vehicle_id',
        'status',
    ];

    protected $casts = [
        'loan_date' => 'date',
        'amount' => 'decimal:2',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    // Relaciones
    public function account()
    {
        return $this->belongsTo(Account::class, 'account_id')->select(['id', 'name']);
    }

    public function responsible()
    {
        return $this->belongsTo(User::class, 'responsible_id')
            ->setConnection('sistema_onix')
            ->from('onix_personal')
            ->select(['id', 'nombre_completo']);
    }

    public function vehicle()
    {
        return $this->belongsTo(Transport::class, 'vehicle_id')
            ->setConnection('sistema_onix')
            ->from('onix_vehiculos')
            ->select(['id', 'name']);
    }

    public function requests()
    {
        return $this->hasMany(Request::class, 'note', 'id') // Relación basada en el campo note como referencia al loan_id
            ->where('type', 'discount') // Solo descuentos generados por préstamos
            ->where('unique_id', 'like', 'L-%'); // Prefijo L- para préstamos
    }
}
