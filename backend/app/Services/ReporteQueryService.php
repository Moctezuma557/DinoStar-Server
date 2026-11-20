<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class ReporteQueryService
{
    private const CATEGORIAS = ['GOTEO_LENTO', 'FIN_BOLSA', 'GOTEO_RAPIDO'];

    /**
     * @param  array{desde?: ?string, hasta?: ?string, paciente_id?: int|string|null, sala?: ?string, enfermera_id?: int|string|null}  $filtros
     * @return array{total_sesiones: int, sesiones_completadas: array{cantidad: int, porcentaje: float}, sesiones_con_alertas: int, tiempo_promedio_respuesta: ?float}
     *
     * Los promedios y las duraciones se expresan en segundos.
     */
    public function resumen(array $filtros = []): array
    {
        $filtros = $this->validarFiltros($filtros);
        $sesiones = $this->rango($this->sesionesBase($filtros), 's.inicio', $filtros)
            ->selectRaw("COUNT(*) AS total, COUNT(*) FILTER (WHERE s.estado = 'FINALIZADA') AS completadas")
            ->first();
        $total = (int) $sesiones->total;
        $completadas = (int) $sesiones->completadas;
        $conAlertas = $this->rango($this->alertasBase($filtros), 'a.created_at', $filtros)
            ->distinct()->count('a.sesion_id');
        $respuesta = $this->atencionesBase($filtros)
            ->selectRaw('AVG(EXTRACT(EPOCH FROM (t.created_at - a.created_at))) AS promedio')
            ->first()->promedio;

        return [
            'total_sesiones' => $total,
            'sesiones_completadas' => [
                'cantidad' => $completadas,
                'porcentaje' => $this->porcentaje($completadas, $total),
            ],
            'sesiones_con_alertas' => $conAlertas,
            'tiempo_promedio_respuesta' => $this->promedio($respuesta),
        ];
    }

    /**
     * @return list<array{tipo: string, cantidad: int, porcentaje: float, tiempo_promedio_resolucion: ?float}>
     */
    public function alertasPorCategoria(array $filtros = []): array
    {
        $filtros = $this->validarFiltros($filtros);
        $cantidades = $this->rango($this->alertasBase($filtros), 'a.created_at', $filtros)
            ->selectRaw('a.tipo, COUNT(*) AS cantidad')->groupBy('a.tipo')->pluck('cantidad', 'tipo');
        $promedios = $this->atencionesBase($filtros)
            ->selectRaw('a.tipo, AVG(EXTRACT(EPOCH FROM (t.created_at - a.created_at))) AS promedio')
            ->groupBy('a.tipo')->pluck('promedio', 'tipo');
        $total = (int) $cantidades->sum();

        return array_map(function (string $tipo) use ($cantidades, $promedios, $total): array {
            $cantidad = (int) ($cantidades[$tipo] ?? 0);

            return [
                'tipo' => $tipo,
                'cantidad' => $cantidad,
                'porcentaje' => $this->porcentaje($cantidad, $total),
                'tiempo_promedio_resolucion' => $this->promedio($promedios[$tipo] ?? null),
            ];
        }, self::CATEGORIAS);
    }

    /** Devuelve una consulta paginable o recorrible con cursor para la exportación. */
    public function sesionesHistoricas(array $filtros = []): Builder
    {
        $filtros = $this->validarFiltros($filtros);
        $numeroAlertas = DB::table('alertas as conteo')->selectRaw('COUNT(*)')
            ->whereColumn('conteo.sesion_id', 's.id');

        return $this->rango($this->sesionesBase($filtros), 's.inicio', $filtros)
            ->join('usuarios as u', 'u.id', '=', 's.enfermera_id')
            ->select([
                's.id', 's.paciente_id', 'p.nombre as paciente', 's.enfermera_id', 'u.nombre as enfermera',
                'p.sala', 'p.numero_cama', 's.inicio', 's.fin', 's.tipo_solucion', 's.vol_total', 's.estado',
            ])
            ->selectRaw('EXTRACT(EPOCH FROM (s.fin - s.inicio))::double precision AS duracion_segundos')
            ->selectSub($numeroAlertas, 'numero_alertas')
            ->orderByDesc('s.inicio')->orderByDesc('s.id');
    }

    /** Una fila por atención y una fila con atención nula para cada alerta sin atender. */
    public function auditoriaSesion(int $sesionId, array $filtros = []): Builder
    {
        $filtros = $this->validarFiltros($filtros);

        return $this->rango($this->alertasBase($filtros), 'a.created_at', $filtros)
            ->where('s.id', $sesionId)
            ->leftJoin('atenciones_alerta as t', 't.alerta_id', '=', 'a.id')
            ->leftJoin('usuarios as u', 'u.id', '=', 't.enfermera_id')
            ->select([
                'a.id', 'a.sesion_id', 'a.tipo', 'a.created_at as hora', 'a.mensaje as descripcion',
                'a.resuelta', 't.id as atencion_id', 't.enfermera_id as atendida_por_id',
                'u.nombre as atendida_por', 't.created_at as fecha_atencion', 't.resolvio',
            ])
            ->selectRaw('EXTRACT(EPOCH FROM (t.created_at - a.created_at))::double precision AS tiempo_respuesta')
            ->orderBy('a.created_at')->orderBy('a.id')->orderBy('t.created_at')->orderBy('t.id');
    }

    private function validarFiltros(array $filtros): array
    {
        $hasta = ['nullable', 'date_format:Y-m-d'];
        if (isset($filtros['desde'], $filtros['hasta'])) {
            $hasta[] = 'after_or_equal:desde';
        }

        return Validator::make($filtros, [
            'desde' => ['nullable', 'date_format:Y-m-d'],
            'hasta' => $hasta,
            'paciente_id' => ['nullable', 'integer', 'between:-999999999999999999,999999999999999999'],
            'sala' => ['nullable', 'string', 'max:50'],
            'enfermera_id' => ['nullable', 'integer', 'between:-999999999999999999,999999999999999999'],
        ])->validate();
    }

    private function sesionesBase(array $filtros): Builder
    {
        $query = DB::table('sesiones as s')->join('pacientes as p', 'p.id', '=', 's.paciente_id');
        foreach (['paciente_id', 'enfermera_id'] as $campo) {
            if (isset($filtros[$campo])) {
                $query->where('s.'.$campo, $filtros[$campo]);
            }
        }
        if (isset($filtros['sala'])) {
            $query->where('p.sala', $filtros['sala']);
        }

        return $query;
    }

    private function alertasBase(array $filtros): Builder
    {
        return $this->sesionesBase($filtros)->join('alertas as a', 'a.sesion_id', '=', 's.id');
    }

    private function atencionesBase(array $filtros): Builder
    {
        return $this->rango(
            $this->alertasBase($filtros)->join('atenciones_alerta as t', 't.alerta_id', '=', 'a.id')
                ->where('t.resolvio', true),
            'a.created_at',
            $filtros,
        );
    }

    /** Los días son inclusivos en app.timezone y se comparan sin transformar la columna indexada. */
    private function rango(Builder $query, string $columna, array $filtros): Builder
    {
        if (isset($filtros['desde'])) {
            $inicio = CarbonImmutable::createFromFormat('!Y-m-d', $filtros['desde'], config('app.timezone'));
            $query->where($columna, '>=', $inicio->toIso8601String());
        }
        if (isset($filtros['hasta'])) {
            $fin = CarbonImmutable::createFromFormat('!Y-m-d', $filtros['hasta'], config('app.timezone'))->addDay();
            $query->where($columna, '<', $fin->toIso8601String());
        }

        return $query;
    }

    private function porcentaje(int $cantidad, int $total): float
    {
        return $total === 0 ? 0.0 : round($cantidad * 100 / $total, 2);
    }

    private function promedio(mixed $valor): ?float
    {
        return $valor === null ? null : round((float) $valor, 2);
    }
}
