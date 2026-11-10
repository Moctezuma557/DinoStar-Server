<?php

namespace App\Services;

use App\Exceptions\LoginFallidoException;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\NewAccessToken;

class AutenticacionService
{
    /**
     * Hash bcrypt valido de una cadena aleatoria. Se compara cuando el correo
     * no existe para que la respuesta tarde lo mismo que con un correo real.
     */
    private const HASH_SENUELO = '$2y$12$0gWNACKOUdQewHaK.l91EO.z3BcZVJpgrbT7r1PMoBsbq6lfvqjH2';

    /**
     * @return array{token: NewAccessToken, usuario: User, recordar: bool}
     *
     * @throws LoginFallidoException
     */
    public function iniciarSesion(string $email, string $password, bool $recordar): array
    {
        $usuario = User::query()
            ->whereRaw('lower(email) = ?', [mb_strtolower(trim($email))])
            ->first();

        $passwordValida = Hash::check($password, $usuario?->password ?? self::HASH_SENUELO);

        if ($usuario === null || ! $passwordValida) {
            throw LoginFallidoException::credencialesInvalidas();
        }

        // Solo se revela que la cuenta esta inactiva a quien ya conoce la contrasena.
        if (! $usuario->activo) {
            throw LoginFallidoException::usuarioInactivo();
        }

        // "Recordar sesion" es un privilegio exclusivo de ENFERMERA.
        $recordarEfectivo = $recordar && $usuario->rol === 'ENFERMERA';

        $token = $usuario->createToken(
            $recordarEfectivo ? 'auth:recordar' : 'auth:sesion',
            ['*'],
            now()->addMinutes($this->minutosDeVigencia($usuario->rol, $recordarEfectivo)),
        );

        return ['token' => $token, 'usuario' => $usuario, 'recordar' => $recordarEfectivo];
    }

    public function minutosDeVigencia(string $rol, bool $recordar): int
    {
        if ($rol !== 'ENFERMERA') {
            return (int) config('auth.tokens.admin');
        }

        return (int) config($recordar ? 'auth.tokens.enfermera_recordar' : 'auth.tokens.enfermera');
    }
}
