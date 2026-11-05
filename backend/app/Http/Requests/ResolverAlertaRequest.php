<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ResolverAlertaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'resuelta' => ['required', 'accepted'],
            // TODO(auth): cuando exista autenticación, tomar la enfermera de $this->user()
            // y dejar de exigir enfermera_id en el body.
            'enfermera_id' => [
                'required',
                'integer',
                Rule::exists('usuarios', 'id')->where('rol', 'ENFERMERA')->where('activo', true),
            ],
            'notas' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
