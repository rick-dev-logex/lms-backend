<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class PersonalResource extends JsonResource
{
    // Aqui mandas las columnas o los datos que van a llegar al front como data. únicamente mando los que creo necesarios mostrar por ahora, para no utilizar tantos recursos y filtrar desde el back end. Si son necesarios más, solo agregas las columnas con este formato:

    // clave -> data
    // Siendo la clave como me va a llegar el JSON y la data lo que sacas de las columnas
    //Es decir, en vez de id => $this->id, puedes poner "hola" => $this->id y en el JSON el id me llega con la clave "hola".

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
            'cargo_logex' => $this->cargo->cargo ?? '', //Aqui obtenemos el nombre del cargo referenciado
            'hora_ingreso_laboral' => $this->hora_ingreso_laboral,
            'hora_salida_laboral' => $this->hora_salida_laboral,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
