<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class CargoResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'cargo' => $this->cargo,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
