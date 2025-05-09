<?php

namespace App\Models;

use App\Notifications\ResetPasswordNotification;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use PHPOpenSourceSaver\JWTAuth\Contracts\JWTSubject;

class User extends Authenticatable implements JWTSubject
{
    use HasApiTokens, HasFactory, Notifiable;

    // protected $connection = 'lms_backend';
    protected $table = 'users';

    protected $fillable = [
        'name',
        'email',
        'dob',
        'phone',
        'password',
        'profile_photo_path',
        'current_team_id',
    ];

    protected $hidden = ['password', 'remember_token'];
    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
        'dob' => 'date',
    ];

    /**
     * Enviar la notificación de reseteo de contraseña
     */
    public function sendPasswordResetNotification($token)
    {
        $this->notify(new ResetPasswordNotification($token));
    }

    public function role()
    {
        return $this->belongsTo(Role::class);
    }

    public function permissions()
    {
        return $this->belongsToMany(Permission::class)->withTimestamps();
    }

    public function assignedProjects()
    {
        return $this->hasOne(UserAssignedProjects::class);
    }
    /**
     * Accesor para obtener los detalles de los proyectos asignados.
     * Se consulta la base de datos sistema_onix (modelo Project).
     */
    public function getProjectDetailsAttribute()
    {
        // Obtiene el registro de asignación de proyectos (en lms_backend)
        $assigned = $this->assignedProjects;
        $projectIds = $assigned ? $assigned->projects : [];

        // Si no hay asignaciones, retorna una colección vacía
        if (empty($projectIds)) {
            return collect([]);
        }

        // Consulta los proyectos en sistema_onix (el modelo Project ya tiene:
        // protected $connection = 'sistema_onix' y la tabla 'onix_proyectos')
        return Project::whereIn('id', $projectIds)
            ->active() // Si tienes un scope active definido en Project
            ->get();
    }

    /**
     * Accesor para obtener únicamente los códigos de proyecto.
     * Suponemos que en el modelo Project, el campo 'proyecto' es el código.
     */
    public function getProjectCodesAttribute()
    {
        return $this->project_details->pluck('proyecto');
    }


    /**
     * Get the identifier that will be stored in the subject claim of the JWT.
     *
     * @return mixed
     */
    public function getJWTIdentifier()
    {
        return $this->getKey();
    }

    /**
     * Return a key value array, containing any custom claims to be added to the JWT.
     *
     * @return array
     */
    public function getJWTCustomClaims()
    {
        return [];
    }
    public function requests()
    {
        $this->hasMany(Request::class);
    }
}
