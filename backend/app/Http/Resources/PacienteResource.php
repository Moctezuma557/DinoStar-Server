<?php

namespace App\Http\Resources;

use App\Models\Lectura;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PacienteResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $session = $this->sesionActiva;

        return [
            'id' => $this->id, 'nombre' => $this->nombre,
            'numero_cama' => $this->numero_cama, 'sala' => $this->sala, 'activo' => $this->activo,
            'created_at' => $this->created_at, 'updated_at' => $this->updated_at,
            'sesion' => [
                'id' => $session->id, 'paciente_id' => $session->paciente_id,
                'enfermera_id' => $session->enfermera_id, 'vol_total' => $session->vol_total,
                'tipo_solucion' => $session->tipo_solucion, 'modo_goteo' => $session->modo_goteo,
                'estado' => $session->estado, 'inicio' => $session->inicio, 'fin' => $session->fin,
                'created_at' => $session->created_at, 'updated_at' => $session->updated_at,
                'lecturas' => $session->lecturas->map(fn (Lectura $reading): array => [
                    'id' => $reading->id, 'sesion_id' => $reading->sesion_id,
                    'gotas_por_min' => $reading->gotas_por_min, 'vol_restante' => $reading->vol_restante,
                    'tiempo_restante_min' => $reading->tiempo_restante_min,
                    'timestamp_dispositivo' => $reading->timestamp_dispositivo,
                    'created_at' => $reading->created_at,
                ]),
            ],
        ];
    }
}
