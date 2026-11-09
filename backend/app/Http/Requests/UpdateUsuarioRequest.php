<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateUsuarioRequest extends StoreUsuarioRequest
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
            'nombre' => ['bail', 'sometimes', 'required', 'string', 'max:150'],
            'email' => ['bail', 'sometimes', 'required', 'string', 'email', 'max:255'],
            'password' => ['sometimes', ...$this->passwordRules()],
            'rol' => ['sometimes', 'required', Rule::in(['ADMIN', 'ENFERMERA'])],
            'turno' => ['sometimes', 'nullable', Rule::in(['MATUTINO', 'VESPERTINO', 'NOCTURNO'])],
            'activo' => ['sometimes', 'required', 'boolean:strict'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if (! $this->hasAny(array_keys($this->rules()))) {
                $validator->errors()->add('usuario', 'Envíe al menos un campo editable.');
            }
        }];
    }
}
