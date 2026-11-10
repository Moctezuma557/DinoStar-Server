<?php

namespace App\Http\Requests;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreUsuarioRequest extends FormRequest
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
            'nombre' => ['bail', 'required', 'string', 'max:150'],
            'email' => ['bail', 'required', 'string', 'email', 'max:255'],
            'password' => $this->passwordRules(),
            'rol' => ['required', Rule::in(['ADMIN', 'ENFERMERA'])],
            'turno' => ['nullable', Rule::in(['MATUTINO', 'VESPERTINO', 'NOCTURNO'])],
            'activo' => ['sometimes', 'required', 'boolean:strict'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => mb_strtolower(trim($this->input('email')))]);
        }
    }

    protected function passwordRules(): array
    {
        return ['bail', 'required', 'string', 'min:8', function (string $attribute, mixed $value, Closure $fail): void {
            // Bcrypt accepts at most 72 bytes, including multibyte characters.
            if (strlen($value) > 72) {
                $fail('La contraseña no debe superar 72 bytes.');
            }
        }];
    }
}
