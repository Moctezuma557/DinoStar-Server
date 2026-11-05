<?php

namespace App\Http\Controllers;

use App\Http\Requests\ResolverAlertaRequest;
use App\Http\Resources\AlertaResource;
use App\Models\Alerta;
use App\Models\AtencionAlerta;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class AlertaController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $filtros = $request->validate([
            'resuelta' => ['nullable', 'in:true,false,1,0'],
            'sesion_id' => ['nullable', 'integer', 'min:1'],
        ]);

        $query = Alerta::with(['sesion.paciente'])->orderByDesc('id');

        if (isset($filtros['resuelta'])) {
            $query->where('resuelta', filter_var($filtros['resuelta'], FILTER_VALIDATE_BOOLEAN));
        }

        if (isset($filtros['sesion_id'])) {
            $query->where('sesion_id', $filtros['sesion_id']);
        }

        return AlertaResource::collection($query->get());
    }

    public function update(ResolverAlertaRequest $request, int $id): AlertaResource|JsonResponse
    {
        $alerta = Alerta::findOrFail($id);

        // TODO(auth): usar $request->user()->getKey() cuando auth esté listo.
        $enfermeraId = $request->user()?->getKey() ?? $request->validated('enfermera_id');

        $yaResuelta = false;

        try {
            DB::transaction(function () use ($request, $alerta, $enfermeraId, &$yaResuelta): void {
                // UPDATE condicional: es atómico. Si dos requests compiten, solo uno afecta la fila.
                $actualizadas = Alerta::whereKey($alerta->getKey())
                    ->where('resuelta', false)
                    ->update(['resuelta' => true]);

                if ($actualizadas === 0) {
                    $yaResuelta = true;

                    return;
                }

                AtencionAlerta::create([
                    'alerta_id' => $alerta->getKey(),
                    'enfermera_id' => $enfermeraId,
                    'notas' => $request->validated('notas'),
                    'resolvio' => true,
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            // uq_atencion_resolutiva: ya existe una atención resolutiva. La transacción hizo rollback.
            $yaResuelta = true;
        }

        if ($yaResuelta) {
            return response()->json(['message' => 'La alerta ya se encuentra resuelta.'], 409);
        }

        return (new AlertaResource($alerta->refresh()->load(['atenciones', 'sesion.paciente'])))
            ->additional(['message' => 'Alerta marcada como atendida exitosamente.']);
    }
}
