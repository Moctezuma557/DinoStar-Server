<?php

namespace Database\Factories;

use App\Models\Paciente;
use App\Models\Sesion;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Sesion>
 */
class SesionFactory extends Factory
{
    protected $model = Sesion::class;

    public function definition(): array
    {
        return [
            'paciente_id' => Paciente::factory(),
            'enfermera_id' => User::factory(),
            'vol_total' => 1000,
            'modo_goteo' => 'NORMAL_GOTEO',
            'tipo_solucion' => 'SALINA_0_9',
            'estado' => 'ACTIVA',
            'inicio' => now(),
            'fin' => null,
        ];
    }
}
