<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class PersonalResource extends JsonResource
{
    // Aqui mandas las columnas o los datos que van a llegar al front como data
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'nombres' => $this->nombres,
            'apellidos' => $this->apellidos,
            'correo_electronico' => $this->correo_electronico,
            'estado_personal' => $this->estado_personal,
            'sexo' => $this->sexo,
            'fecha_nacimiento' => $this->fecha_nacimiento,
            'estado_civil' => $this->estado_civil,
            'fecha_ingreso_iess' => $this->fecha_ingreso_iess,
            'estado_contractual' => $this->estado_contractual,
            'sectorial' => $this->sectorial,
            'fecha_contrato' => $this->fecha_contrato,
            'proyecto' => $this->proyecto,
            'area' => $this->area,
            'subarea' => $this->subarea,
            'cargo_logex' => $this->cargo_logex,
            'hora_ingreso_laboral' => $this->hora_ingreso_laboral,
            'hora_salida_laboral' => $this->hora_salida_laboral,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
