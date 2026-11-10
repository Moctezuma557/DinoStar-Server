<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdatePacienteRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'nombre' => ['sometimes', 'bail', 'required', 'string', 'max:150', 'regex:/\S/u'],
            'numero_cama' => ['sometimes', 'bail', 'required', 'string', 'max:20', 'regex:/\S/u'],
            'sala' => ['sometimes', 'bail', 'required', 'string', 'max:50', 'regex:/\S/u'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if (! $this->hasAny(['nombre', 'numero_cama', 'sala'])) {
                $validator->errors()->add('paciente', 'Indique al menos un campo: nombre, numero_cama o sala.');
            }
        }];
    }
}
