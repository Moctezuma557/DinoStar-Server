<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AtencionAlertaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'alerta_id' => $this->alerta_id,
            'enfermera_id' => $this->enfermera_id,
            'notas' => $this->notas,
            'resolvio' => (bool) $this->resolvio,
            'created_at' => $this->created_at,
        ];
    }
}
