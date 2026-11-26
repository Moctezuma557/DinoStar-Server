<?php

namespace App\Http\Resources;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * K2: una fila de la tabla de sesiones históricas.
 *
 * Columnas de la tabla de sesiones de la "Definición de KPIs" de B1: paciente, enfermera, ubicación,
 * inicio/fin, duración, solución/volumen, nº de alertas y estado (`id` solo sirve para pedir su auditoría).
 * Sin IDs de tabla de paciente ni de enfermera: la cama viaja en `ubicacion.numero_cama`.
 * El recurso envuelve la fila (stdClass) que devuelve ReporteQueryService::sesionesHistoricas().
 * La duración va en segundos, como los demás tiempos de B1, y es null mientras la sesión no termina.
 */
class SesionReporteResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'paciente' => $this->paciente,
            'enfermera' => $this->enfermera,
            'ubicacion' => ['sala' => $this->sala, 'numero_cama' => $this->numero_cama],
            'inicio' => $this->fecha($this->inicio),
            'fin' => $this->fecha($this->fin),
            'duracion_segundos' => $this->duracion_segundos === null ? null : (float) $this->duracion_segundos,
            'tipo_solucion' => $this->tipo_solucion,
            'vol_total' => $this->vol_total === null ? null : (float) $this->vol_total,
            'numero_alertas' => (int) $this->numero_alertas,
            'estado' => $this->estado,
        ];
    }

    private function fecha(?string $valor): ?string
    {
        return $valor === null ? null : CarbonImmutable::parse($valor)->toJSON();
    }
}
