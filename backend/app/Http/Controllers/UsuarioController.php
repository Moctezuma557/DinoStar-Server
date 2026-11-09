<?php

namespace App\Http\Controllers;

use App\Http\Requests\ListUsuarioRequest;
use App\Http\Requests\StoreUsuarioRequest;
use App\Http\Requests\UpdateUsuarioRequest;
use App\Http\Resources\UsuarioResource;
use App\Models\User;
use App\Services\GestionUsuarioService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class UsuarioController extends Controller
{
    public function index(ListUsuarioRequest $request): AnonymousResourceCollection
    {
        $filters = $request->validated();
        $query = User::query();
        foreach (['rol', 'activo'] as $field) {
            if (isset($filters[$field])) {
                $query->where($field, $filters[$field]);
            }
        }
        if (isset($filters['nombre'])) {
            $name = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $filters['nombre']);
            $query->where('nombre', 'ilike', '%'.$name.'%');
        }

        return UsuarioResource::collection($query->orderBy('nombre')->orderBy('id')->get());
    }

    public function store(StoreUsuarioRequest $request, GestionUsuarioService $service): JsonResponse
    {
        return (new UsuarioResource($service->crear($request->validated())))->response()->setStatusCode(201);
    }

    public function update(UpdateUsuarioRequest $request, string $id, GestionUsuarioService $service): UsuarioResource
    {
        return new UsuarioResource($service->actualizar($id, $request->validated()));
    }

    public function destroy(string $id, GestionUsuarioService $service): Response
    {
        $service->darDeBaja($id);

        return response()->noContent();
    }
}
