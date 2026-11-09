<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListUsuarioRequest extends FormRequest
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
        return [
            'rol' => ['nullable', Rule::in(['ADMIN', 'ENFERMERA'])],
            'activo' => ['nullable', 'boolean'],
            'nombre' => ['nullable', 'string', 'max:150'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->input('activo') === 'true') {
            $this->merge(['activo' => true]);
        } elseif ($this->input('activo') === 'false') {
            $this->merge(['activo' => false]);
        }
    }
}
