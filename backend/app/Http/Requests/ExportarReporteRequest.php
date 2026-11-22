<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ExportarReporteRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $fin = ['nullable', 'date_format:Y-m-d'];
        if ($this->filled('fecha_inicio')) {
            $fin[] = 'after_or_equal:fecha_inicio';
        }

        return [
            'fecha_inicio' => ['nullable', 'date_format:Y-m-d'],
            'fecha_fin' => $fin,
            'paciente_id' => ['nullable', 'integer', 'between:-999999999999999999,999999999999999999'],
            'sala' => ['nullable', 'string', 'max:50'],
            'enfermera_id' => ['nullable', 'integer', 'between:-999999999999999999,999999999999999999'],
        ];
    }

    /** @return array{desde: ?string, hasta: ?string, paciente_id: int|string|null, sala: ?string, enfermera_id: int|string|null} */
    public function filtros(): array
    {
        $datos = $this->validated();

        return [
            'desde' => $datos['fecha_inicio'] ?? null,
            'hasta' => $datos['fecha_fin'] ?? null,
            'paciente_id' => $datos['paciente_id'] ?? null,
            'sala' => $datos['sala'] ?? null,
            'enfermera_id' => $datos['enfermera_id'] ?? null,
        ];
    }
}
