<?php

namespace Database\Factories;

use App\Models\Paciente;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Paciente>
 */
class PacienteFactory extends Factory
{
    protected $model = Paciente::class;

    public function definition(): array
    {
        return [
            'nombre' => fake()->name(),
            // (sala, numero_cama) debe ser único entre pacientes activos.
            'numero_cama' => 'cama-'.fake()->unique()->numberBetween(1, 99999),
            'sala' => 'Sala '.fake()->randomElement(['A', 'B', 'C']),
            'activo' => true,
        ];
    }
}
