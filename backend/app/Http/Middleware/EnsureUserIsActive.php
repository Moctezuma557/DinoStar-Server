<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Un token vigente deja de servir si el usuario fue dado de baja despues de
 * iniciar sesion (DELETE /api/usuarios/{id} es baja logica, no borra tokens
 * escritos por otras vias). Alias: "activo".
 */
class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || ! $user->activo) {
            $user?->tokens()->delete();

            return response()->json([
                'message' => 'La cuenta está desactivada. Contacta a un administrador.',
                'code' => 'usuario_inactivo',
            ], 401);
        }

        return $next($request);
    }
}
