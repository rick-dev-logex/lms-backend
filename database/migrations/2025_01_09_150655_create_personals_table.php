<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('personals', function (Blueprint $table) {
            // Campos base
            $table->uuid('id')->primary();
            $table->string('name');
            $table->timestamp('date_entered')->nullable();
            $table->timestamp('date_modified')->nullable();
            $table->uuid('modified_user_id')->nullable();
            $table->uuid('created_by')->nullable();
            $table->text('description')->nullable();
            $table->boolean('deleted')->default(false);
            $table->uuid('assigned_user_id')->nullable();

            // Información personal
            $table->string('nombres');
            $table->string('apellidos');
            $table->string('telefono_oficina')->nullable();
            $table->string('telefono_movil')->nullable();
            $table->string('telefono_casa')->nullable();
            $table->string('estado_personal')->nullable();
            $table->string('sexo')->nullable();
            $table->string('correo_electronico')->nullable();
            $table->boolean('no_volver_a_contratar')->default(false);
            $table->date('fecha_nacimiento')->nullable();
            $table->string('estado_civil')->nullable();

            // Información financiera
            $table->decimal('sueldo', 15, 6)->default(0);
            $table->string('currency_id')->nullable();

            // Información laboral
            $table->date('fecha_ingreso_iess')->nullable();
            $table->string('estado_contractual')->nullable();
            $table->string('sectorial')->nullable();
            $table->date('fecha_contrato')->nullable();
            $table->date('fecha_cesante')->nullable();

            // Información de licencia
            $table->string('tiene_licencia')->nullable();
            $table->string('tipo_licencia')->nullable();
            $table->decimal('puntaje_licencia', 5, 2)->default(0)->nullable();

            // Direcciones
            $table->string('direccion_personal_city')->nullable();
            $table->string('direccion_personal_state')->nullable();
            $table->string('direccion_personal_postalcode')->nullable();
            $table->string('direccion_personal_country')->nullable();
            $table->string('direccion_personal')->nullable();
            $table->string('direccion_laboral_city')->nullable();
            $table->string('direccion_laboral_state')->nullable();
            $table->string('direccion_laboral_postalcode')->nullable();
            $table->string('direccion_laboral_country')->nullable();
            $table->string('direccion_laboral')->nullable();
            $table->string('direccion_fiscal_city')->nullable();
            $table->string('direccion_fiscal_state')->nullable();
            $table->string('direccion_fiscal_postalcode')->nullable();
            $table->string('direccion_fiscal_country')->nullable();
            $table->string('direccion_fiscal')->nullable();

            // Campos adicionales
            $table->string('foto')->nullable();
            $table->string('user_id_c')->nullable();
            $table->string('ruc')->nullable();
            $table->string('nombre_completo')->nullable();
            $table->string('proyecto')->nullable();
            $table->string('area')->nullable();
            $table->string('rol_tms')->nullable();
            $table->string('urlPhotoChoferCedula')->nullable();
            $table->string('urlPhotoChoferLicencia')->nullable();
            $table->string('urlPhotoUsuario')->nullable();

            // Periodos de trabajo
            $table->date('ingreso1')->nullable();
            $table->date('salida1')->nullable();
            $table->date('ingreso2')->nullable();
            $table->date('salida2')->nullable();
            $table->date('ingreso3')->nullable();
            $table->date('salida3')->nullable();
            $table->date('ingreso4')->nullable();
            $table->date('salida4')->nullable();
            $table->date('ingreso5')->nullable();
            $table->date('salida5')->nullable();

            // Configuraciones laborales
            $table->string('gana_horas_extras')->nullable();
            $table->string('alimentacion')->nullable();
            $table->time('hora_ingreso_laboral')->nullable();
            $table->time('hora_salida_laboral')->nullable();
            $table->string('cargo_logex')->default("No Definido")->nullable();
            $table->boolean('teletrabajo')->default(false);
            $table->boolean('pago_sueldo')->default(true);
            $table->boolean('asignacion_multiple')->default(false);
            $table->boolean('acumula_decimo')->default(false);
            $table->boolean('acumula_decimo_cuarto')->default(false);
            $table->string('subarea')->nullable();
            $table->boolean('no_marca')->default(false);

            // Contacto de emergencia
            $table->string('nombre_contacto_emergencia')->nullable();
            $table->string('telefono_contacto_emergencia')->nullable();
            $table->string('parentezco_contacto_emergencia')->nullable();

            // Información adicional
            $table->boolean('validacion_antecedentes')->default(false);
            $table->string('tipo_pago')->nullable();
            $table->boolean('marcacion_otro')->default(false);
            $table->boolean('discapacidad')->default(false);

            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('personals');
    }
};
