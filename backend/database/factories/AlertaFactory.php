<?php

namespace Database\Factories;

use App\Models\Alerta;
use App\Models\Sesion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Alerta>
 */
class AlertaFactory extends Factory
{
    protected $model = Alerta::class;

    public function definition(): array
    {
        return [
            'sesion_id' => Sesion::factory(),
            'tipo' => fake()->randomElement(['FIN_BOLSA', 'GOTEO_LENTO', 'GOTEO_RAPIDO', 'DESCONECTADO']),
            'mensaje' => fake()->sentence(),
            'resuelta' => false,
        ];
    }

    public function resuelta(): static
    {
        return $this->state(fn (array $attributes) => ['resuelta' => true]);
    }
}
