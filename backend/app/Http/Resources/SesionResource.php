<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SesionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'paciente_id' => $this->paciente_id,
            'enfermera_id' => $this->enfermera_id,
            'vol_total' => $this->vol_total,
            'modo_goteo' => $this->modo_goteo,
            'tipo_solucion' => $this->tipo_solucion,
            'estado' => $this->estado,
            'inicio' => $this->inicio,
            'fin' => $this->fin,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
