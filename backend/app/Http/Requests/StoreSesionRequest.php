<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSesionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'paciente_id' => ['required', 'integer', Rule::exists('pacientes', 'id')->where('activo', true)],
            'enfermera_id' => [
                'required',
                'integer',
                'exists:usuarios,id',
                function ($attribute, $value, $fail) {
                    $user = User::find($value);
                    if (! $user || $user->rol !== 'ENFERMERA' || ! $user->activo) {
                        $fail('La enfermera seleccionada debe estar activa y contar con el rol ENFERMERA.');
                    }
                },
            ],
            'vol_total' => ['required', 'numeric', 'gt:0', 'max:5000'],
            'modo_goteo' => ['required', Rule::in(['NORMAL_GOTEO', 'MICRO_GOTEO'])],
            'tipo_solucion' => ['required', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'paciente_id.exists' => 'El paciente no existe o no está activo.',
            'modo_goteo.in' => 'El modo de goteo debe ser NORMAL_GOTEO o MICRO_GOTEO.',
        ];
    }
}
