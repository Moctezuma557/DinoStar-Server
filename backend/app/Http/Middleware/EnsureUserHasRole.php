<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restringe una ruta a uno o mas roles. Uso: ->middleware('rol:ADMIN') o
 * ->middleware('rol:ADMIN,ENFERMERA'). Debe ir despues de auth:sanctum.
 * Sin usuario autenticado responde 401; con rol distinto, 403.
 */
class EnsureUserHasRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if ($user === null) {
            return response()->json(['message' => 'No autenticado.', 'code' => 'no_autenticado'], 401);
        }

        if (! in_array($user->rol, $roles, true)) {
            return response()->json([
                'message' => 'No tienes permisos para realizar esta acción.',
                'code' => 'rol_no_autorizado',
            ], 403);
        }

        return $next($request);
    }
}
