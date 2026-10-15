<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePacienteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'nombre' => ['bail', 'required', 'string', 'max:150', 'regex:/\S/u'],
            'numero_cama' => ['bail', 'required', 'string', 'max:20', 'regex:/\S/u'],
            'sala' => ['bail', 'required', 'string', 'max:50', 'regex:/\S/u'],
            'vol_total' => ['bail', 'required', 'numeric', 'gt:0', 'max:5000', 'decimal:0,2'],
            'modo_goteo' => ['required', Rule::in(['NORMAL_GOTEO', 'MICRO_GOTEO'])],
            'tipo_solucion' => ['bail', 'required', 'string', 'max:255', 'regex:/\S/u'],
            'enfermera_id' => ['bail', 'required', 'integer', 'between:-999999999999999999,999999999999999999',
                Rule::exists(User::class, 'id')->where('rol', 'ENFERMERA')->where('activo', true)],
        ];
    }

    public function messages(): array
    {
        return [
            '*.required' => 'El campo :attribute es obligatorio.',
            '*.regex' => 'El campo :attribute no puede estar en blanco.',
            'enfermera_id.exists' => 'La enfermera indicada no existe o no está activa.',
            'modo_goteo.in' => 'El modo debe ser NORMAL_GOTEO o MICRO_GOTEO.',
            'vol_total.gt' => 'El volumen total debe ser mayor que cero.',
            'vol_total.max' => 'El volumen total no debe superar 5000 mL.',
            'vol_total.decimal' => 'El volumen total admite hasta dos decimales.',
        ];
    }
}
