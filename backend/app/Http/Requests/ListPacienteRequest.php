<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ListPacienteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'nombre' => ['nullable', 'string', 'max:150'],
            'enfermera_id' => ['nullable', 'integer', 'between:-999999999999999999,999999999999999999'],
        ];
    }
}
