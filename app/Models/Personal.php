<?php

namespace App\Models; // Espacio de nombres: Indica que el archivo pertenece al directorio App\Models.

use Illuminate\Database\Eloquent\Model; // Model: Extiende la clase base Model de Eloquent para habilitar funcionalidades ORM.
use Illuminate\Database\Eloquent\Concerns\HasUuids; //HasUuids: Permite que el modelo use UUIDs en lugar de claves numéricas incrementales para identificar registros.

class Personal extends Model
{
    use HasUuids;

    // ¡MUY IMPORTANTE! SI o SI debes poner este protected $fillable para que se pueda guardar
    // la información de cada columna, sino, si omites uno solo, esa columna no se va a 
    // actualizar y, dependiendo de como se almacene el dato, puede dar un error que te va a 
    // dar un dolor de cabeza buscar donde se dio. Muy atento a esto, debe coincidir con las 
    // migraciones.

    /**
     * Importancia: Define qué columnas de la tabla personals se pueden llenar mediante asignación masiva. Esto es crítico para protegerse contra ataques de asignación masiva.
     * 
     * Precisión: Asegúrate de que todos los campos en las migraciones coincidan aquí. Si omites alguno, no se guardará correctamente, y podrías enfrentarte a errores difíciles de depurar.
     */

    protected $fillable = [
        'name',
        'nombres',
        'apellidos',
        'nombre_completo',
        'telefono_oficina',
        'telefono_movil',
        'telefono_casa',
        'correo_electronico',
        'estado_personal',
        'sexo',
        'fecha_nacimiento',
        'estado_civil',
        'sueldo',
        'currency_id',
        'fecha_ingreso_iess',
        'estado_contractual',
        'sectorial',
        'fecha_contrato',
        'fecha_cesante',
        'tiene_licencia',
        'tipo_licencia',
        'puntaje_licencia',
        'direccion_personal',
        'direccion_personal_city',
        'direccion_personal_state',
        'direccion_personal_country',
        'direccion_personal_postalcode',
        'direccion_laboral',
        'direccion_laboral_city',
        'direccion_laboral_state',
        'direccion_laboral_country',
        'direccion_laboral_postalcode',
        'proyecto',
        'area',
        'subarea',
        'cargo_logex',
        'hora_ingreso_laboral',
        'hora_salida_laboral',
        'ingreso1',
        'salida1',
        'ingreso2',
        'salida2',
        'ingreso3',
        'salida3',
        'ingreso4',
        'salida4',
        'ingreso5',
        'salida5',
        'teletrabajo',
        'pago_sueldo',
        'asignacion_multiple',
        'acumula_decimo',
        'acumula_decimo_cuarto',
        'no_marca',
        'nombre_contacto_emergencia',
        'telefono_contacto_emergencia',
        'parentezco_contacto_emergencia',
        'validacion_antecedentes',
        'discapacidad',
        'tipo_pago',
        'assigned_user_id',
    ];

    /**
     * Propósito: Define cómo se deben convertir automáticamente los atributos de la base de datos al trabajar con ellos en la aplicación.
     * 
     * Fechas (date): Convierte columnas a instancias de Carbon para manejar fechas fácilmente.
     * Booleanos (boolean): Convierte valores de la base de datos (1 o 0) a true o false en PHP.
     * Decimal: Controla la precisión de los valores numéricos.
     * Datetime: Maneja fechas con tiempo (hora de entrada/salida).
     * 
     */

    protected $casts = [
        'fecha_nacimiento' => 'date',
        'fecha_ingreso_iess' => 'date',
        'fecha_contrato' => 'date',
        'fecha_cesante' => 'date',
        'ingreso1' => 'date',
        'salida1' => 'date',
        'ingreso2' => 'date',
        'salida2' => 'date',
        'ingreso3' => 'date',
        'salida3' => 'date',
        'ingreso4' => 'date',
        'salida4' => 'date',
        'ingreso5' => 'date',
        'salida5' => 'date',
        'hora_ingreso_laboral' => 'datetime',
        'hora_salida_laboral' => 'datetime',
        'sueldo' => 'decimal:6',
        'puntaje_licencia' => 'decimal:2',
        'teletrabajo' => 'boolean',
        'pago_sueldo' => 'boolean',
        'asignacion_multiple' => 'boolean',
        'acumula_decimo' => 'boolean',
        'acumula_decimo_cuarto' => 'boolean',
        'no_marca' => 'boolean',
        'validacion_antecedentes' => 'boolean',
        'discapacidad' => 'boolean',
        'deleted' => 'boolean',
    ];

    //Aquí pones las relaciones
    public function cargo()
    {
        return $this->belongsTo(Cargo::class, 'cargo_logex', 'id'); // La clave foránea es 'cargo_id'
    }
}

// Para obtener el cargo de un personal:

// $personal = Personal::find(1);
// $cargo = $personal->cargo; // Obtiene el cargo asociado a este personal

// Para obtener todos los personales de un cargo:

// $cargo = Cargo::find(1);
// $personals = $cargo->personals; // Obtiene todos los personales asociados a este cargo
