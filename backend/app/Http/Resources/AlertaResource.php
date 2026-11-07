<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AlertaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        // La cama no es una entidad: es la columna numero_cama del paciente.
        // No se reutiliza PacienteResource porque exige una sesión activa cargada.
        $paciente = $this->relationLoaded('sesion') ? $this->sesion?->paciente : null;

        return [
            'id' => $this->id,
            'sesion_id' => $this->sesion_id,
            'tipo' => $this->tipo,
            'mensaje' => $this->mensaje,
            'resuelta' => (bool) $this->resuelta,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'paciente' => $paciente ? [
                'id' => $paciente->id,
                'nombre' => $paciente->nombre,
                'sala' => $paciente->sala,
                'numero_cama' => $paciente->numero_cama,
            ] : null,
            'cama' => $paciente?->numero_cama,
            'atenciones' => AtencionAlertaResource::collection($this->whenLoaded('atenciones')),
        ];
    }
}
