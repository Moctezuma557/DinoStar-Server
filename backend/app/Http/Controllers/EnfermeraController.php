<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Http\JsonResponse;

class EnfermeraController extends Controller
{
    /** Listar enfermeras activas. Acepta filtro opcional ?id= para una enfermera específica. */
    public function index(Request $request): JsonResponse
    {
        $query = User::query()
            ->where('rol', 'ENFERMERA')
            ->where('activo', true)
            ->orderBy('nombre');

        if ($request->has('id') && $request->id !== '') {
            $query->where('id', $request->id);
        }

        if ($request->has('nombre') && $request->nombre !== '') {
            $name = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $request->nombre);
            $query->where('nombre', 'ilike', '%'.$name.'%');
        }

        $enfermeras = $query->get(['id', 'nombre', 'email', 'rol', 'turno', 'activo']);

        return response()->json(['data' => $enfermeras]);
    }

    /** Obtener una enfermera específica por ID */
    public function show(string $id): JsonResponse
    {
        $enfermera = User::query()
            ->where('rol', 'ENFERMERA')
            ->where('activo', true)
            ->where('id', $id)
            ->first(['id', 'nombre', 'email', 'rol', 'turno', 'activo']);

        if (!$enfermera) {
            return response()->json(['message' => 'Enfermera no encontrada.'], 404);
        }

        return response()->json(['data' => $enfermera]);
    }

    /** Crear una nueva enfermera */
    public function store(Request $request): JsonResponse
    {
        $validated = Validator::make($request->all(), [
            'nombre' => ['required', 'string', 'max:150', 'regex:/\S/u'],
            'email' => ['required', 'email', 'max:255', 'unique:usuarios,email'],
            'password' => ['required', 'string', 'min:8'],
            'rol' => ['required', 'string', 'in:ENFERMERA'],
            'turno' => ['nullable', 'string', 'max:20'],
            'activo' => ['boolean'],
        ])->validate();

        $user = User::create([
            'nombre' => $validated['nombre'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'rol' => $validated['rol'],
            'turno' => $validated['turno'] ?? null,
            'activo' => $validated['activo'] ?? true,
        ]);

        return response()->json([
            'data' => [
                'id' => $user->id,
                'nombre' => $user->nombre,
                'email' => $user->email,
                'rol' => $user->rol,
                'turno' => $user->turno,
                'activo' => $user->activo,
            ],
        ], 201);
    }
}
