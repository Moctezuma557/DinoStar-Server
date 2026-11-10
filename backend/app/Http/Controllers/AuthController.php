<?php

namespace App\Http\Controllers;

use App\Http\Requests\LoginRequest;
use App\Http\Resources\UsuarioResource;
use App\Services\AutenticacionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    public function login(LoginRequest $request, AutenticacionService $service): JsonResponse
    {
        $resultado = $service->iniciarSesion(
            $request->validated('email'),
            $request->validated('password'),
            $request->boolean('recordar'),
        );

        return response()->json(['data' => [
            'token' => $resultado['token']->plainTextToken,
            'token_type' => 'Bearer',
            'expires_at' => $resultado['token']->accessToken->expires_at->toISOString(),
            'recordar' => $resultado['recordar'],
            'usuario' => new UsuarioResource($resultado['usuario']),
        ]]);
    }

    public function logout(Request $request): JsonResponse
    {
        // Solo el token usado en esta peticion; otras sesiones del usuario siguen vivas.
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Sesión cerrada correctamente.']);
    }

    public function me(Request $request): UsuarioResource
    {
        return new UsuarioResource($request->user());
    }
}
