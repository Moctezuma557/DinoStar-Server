<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PacienteResumenResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'nombre' => $this->nombre,
            'numero_cama' => $this->numero_cama,
            'sala' => $this->sala,
            'tipo_solucion' => $this->sesionActiva->tipo_solucion,
            'modo_goteo' => $this->sesionActiva->modo_goteo,
            'vol_total' => $this->sesionActiva->vol_total,
            'enfermera_id' => $this->sesionActiva->enfermera_id,
        ];
    }
}
