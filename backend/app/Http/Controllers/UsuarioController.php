<?php

namespace App\Http\Controllers;

use App\Http\Requests\ListUsuarioRequest;
use App\Http\Requests\StoreUsuarioRequest;
use App\Http\Requests\UpdateUsuarioRequest;
use App\Http\Resources\UsuarioResource;
use App\Models\User;
use App\Services\AuditoriaService;
use App\Services\GestionUsuarioService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class UsuarioController extends Controller
{
    public function index(ListUsuarioRequest $request, AuditoriaService $auditoria): AnonymousResourceCollection
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

        $users = DB::transaction(function () use ($query, $auditoria): Collection {
            $users = $query->orderBy('nombre')->orderBy('id')->get();
            $auditoria->registrar('LISTAR', 'usuarios');

            return $users;
        });

        return UsuarioResource::collection($users);
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
