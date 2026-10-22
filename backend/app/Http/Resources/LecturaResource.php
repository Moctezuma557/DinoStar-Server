<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LecturaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'gotas_por_min' => $this->gotas_por_min,
            'vol_restante' => $this->vol_restante,
            'tiempo_restante_min' => $this->tiempo_restante_min,
            'timestamp_dispositivo' => $this->timestamp_dispositivo,
        ];
    }
}
