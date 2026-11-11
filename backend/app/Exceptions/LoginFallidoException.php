<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;

/** Login rechazado (401). `codigo` permite al frontend distinguir el motivo. */
class LoginFallidoException extends Exception
{
    private function __construct(string $message, public readonly string $codigo)
    {
        parent::__construct($message);
    }

    public static function credencialesInvalidas(): self
    {
        return new self('Credenciales inválidas.', 'credenciales_invalidas');
    }

    public static function usuarioInactivo(): self
    {
        return new self('La cuenta está desactivada. Contacta a un administrador.', 'usuario_inactivo');
    }

    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage(), 'code' => $this->codigo], 401);
    }
}
