<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreSesionRequest;
use App\Http\Requests\UpdateSesionRequest;
use App\Http\Resources\SesionResource;
use App\Models\Sesion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class SesionController extends Controller
{
    public function store(StoreSesionRequest $request): SesionResource|JsonResponse
    {
        $exists = Sesion::where('paciente_id', $request->paciente_id)
            ->whereIn('estado', ['ACTIVA', 'PAUSADA'])
            ->exists();

        if ($exists) {
            return response()->json([
                'message' => 'El paciente ya tiene una sesión ACTIVA o PAUSADA.',
            ], Response::HTTP_CONFLICT);
        }

        $sesion = Sesion::create([
            ...$request->validated(),
            'estado' => 'ACTIVA',
            'inicio' => now(),
        ]);

        return (new SesionResource($sesion))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function update(UpdateSesionRequest $request, int $id): SesionResource|JsonResponse
    {
        $sesion = Sesion::find($id);

        if (! $sesion) {
            return response()->json([
                'message' => 'Sesión no encontrada.',
            ], Response::HTTP_NOT_FOUND);
        }

        if ($sesion->estado === 'FINALIZADA') {
            return response()->json([
                'message' => 'No se puede editar una sesión finalizada.',
            ], Response::HTTP_CONFLICT);
        }

        $sesion->update($request->validated());

        return new SesionResource($sesion);
    }

    public function finalizar(int $id): SesionResource|JsonResponse
    {
        $sesion = Sesion::find($id);

        if (! $sesion) {
            return response()->json([
                'message' => 'Sesión no encontrada.',
            ], Response::HTTP_NOT_FOUND);
        }

        if ($sesion->estado === 'FINALIZADA') {
            return response()->json([
                'message' => 'La sesión ya se encuentra finalizada.',
            ], Response::HTTP_CONFLICT);
        }

        $sesion->update([
            'estado' => 'FINALIZADA',
            'fin' => now(),
        ]);

        return new SesionResource($sesion);
    }
}
