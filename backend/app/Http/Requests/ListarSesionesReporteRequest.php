<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;

/**
 * K2: filtros de los reportes más paginación de la tabla de sesiones históricas.
 *
 * Hereda los cinco filtros de K1 (fecha_inicio, fecha_fin, paciente_id, sala,
 * enfermera_id) y agrega `page` y `per_page`. Con `per_page` ausente se usan 10 filas.
 */
class ListarSesionesReporteRequest extends FiltrosReporteRequest
{
    public const POR_PAGINA = 10;

    public const MAXIMO_POR_PAGINA = 100;

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'page' => ['nullable', 'integer', 'between:1,1000000'],
            'per_page' => ['nullable', 'integer', 'between:1,'.self::MAXIMO_POR_PAGINA],
        ];
    }

    /** @return array{page: int, per_page: int} */
    public function paginacion(): array
    {
        $datos = $this->validated();

        return [
            'page' => (int) ($datos['page'] ?? 1),
            'per_page' => (int) ($datos['per_page'] ?? self::POR_PAGINA),
        ];
    }
}
