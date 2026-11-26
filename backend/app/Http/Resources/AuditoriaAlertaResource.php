<?php

namespace App\Http\Resources;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * K2: una fila de la auditoría de alertas de una sesión.
 *
 * Columnas de la auditoría de B1: tipo, hora, descripción, atendida por y tiempo de respuesta
 * (`id` es el de la alerta y `resolvio` distingue una atención que no resolvió de una resolutiva).
 * Envuelve la fila de ReporteQueryService::auditoriaSesion(): una fila por atención y una
 * con atención nula por cada alerta sin atender. El tiempo de respuesta sigue la regla de B1:
 * atenciones_alerta.created_at - alertas.created_at, solo si la atención tiene resolvio=true;
 * en cualquier otro caso es null (sin atención o atención que no resolvió).
 */
class AuditoriaAlertaResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $resolvio = $this->resolvio === null ? null : (bool) $this->resolvio;

        return [
            'id' => (int) $this->id,
            'tipo' => $this->tipo,
            'hora' => $this->fecha($this->hora),
            'descripcion' => $this->descripcion,
            'atendida_por' => $this->atendida_por,
            'resolvio' => $resolvio,
            'tiempo_respuesta' => $resolvio === true && $this->tiempo_respuesta !== null
                ? (float) $this->tiempo_respuesta
                : null,
        ];
    }

    private function fecha(?string $valor): ?string
    {
        return $valor === null ? null : CarbonImmutable::parse($valor)->toJSON();
    }
}
