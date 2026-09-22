<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'nombre' => fake()->name(),
            'email' => strtolower(fake()->unique()->safeEmail()),
            'rol' => 'ENFERMERA',
            'turno' => 'MATUTINO',
            'activo' => true,
            'password' => static::$password ??= Hash::make('password'),
        ];
    }

    /**
     * Create an administrator without a nursing shift.
     */
    public function admin(): static
    {
        return $this->state(fn (array $attributes) => [
            'rol' => 'ADMIN',
            'turno' => null,
        ]);
    }
}
