<?php

namespace App\Http\Controllers;

use App\Http\Requests\ListPacienteRequest;
use App\Http\Requests\StorePacienteRequest;
use App\Http\Resources\PacienteResource;
use App\Http\Resources\PacienteResumenResource;
use App\Models\Paciente;
use App\Services\RegistroPacienteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class PacienteController extends Controller
{
    public function index(ListPacienteRequest $request): AnonymousResourceCollection
    {
        $filters = $request->validated();
        $query = Paciente::query()->where('activo', true)->with('sesionActiva')
            ->whereHas('sesionActiva', function ($session) use ($filters): void {
                if (isset($filters['enfermera_id'])) {
                    $session->where('enfermera_id', $filters['enfermera_id']);
                }
            });
        if (isset($filters['nombre'])) {
            // Treat SQL wildcard characters as literal parts of the name.
            $name = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $filters['nombre']);
            $query->where('nombre', 'ilike', '%'.$name.'%');
        }

        // MQTT locks the patient before replacing a session. Keep the eager load
        // in the same locked transaction so a session cannot disappear between queries.
        $patients = DB::transaction(fn () => $query->orderBy('numero_cama')->orderBy('id')->sharedLock()->get());

        return PacienteResumenResource::collection($patients);
    }

    public function store(StorePacienteRequest $request, RegistroPacienteService $service): JsonResponse
    {
        $patient = $service->registrar($request->validated());

        return (new PacienteResource($patient))->response()->setStatusCode(201)
            ->header('Location', route('pacientes.show', ['id' => $patient->id]));
    }

    public function show(string $id): PacienteResource
    {
        $patient = DB::transaction(fn () => Paciente::query()->where('activo', true)->whereHas('sesionActiva')
            ->with(['sesionActiva.lecturas' => fn ($query) => $query->orderBy('timestamp_dispositivo')->orderBy('id')])
            ->sharedLock()->find($id));
        abort_if($patient === null, 404, 'Paciente no encontrado o sin sesión activa.');

        return new PacienteResource($patient);
    }
}
