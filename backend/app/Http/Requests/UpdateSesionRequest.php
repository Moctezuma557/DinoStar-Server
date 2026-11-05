<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSesionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'vol_total' => ['sometimes', 'numeric', 'gt:0', 'max:5000'],
            'modo_goteo' => ['sometimes', Rule::in(['NORMAL_GOTEO', 'MICRO_GOTEO'])],
            'tipo_solucion' => ['sometimes', 'string', 'max:255'],
            'enfermera_id' => [
                'sometimes',
                'integer',
                'exists:usuarios,id',
                function ($attribute, $value, $fail) {
                    $user = User::find($value);
                    if (! $user || $user->rol !== 'ENFERMERA' || ! $user->activo) {
                        $fail('La enfermera seleccionada debe estar activa y contar con el rol ENFERMERA.');
                    }
                },
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'modo_goteo.in' => 'El modo de goteo debe ser NORMAL_GOTEO o MICRO_GOTEO.',
        ];
    }
}
