<?php

namespace App\Http\Controllers;

use App\Http\Resources\LecturaResource;
use App\Models\Sesion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class SesionLecturaController extends Controller
{
    /**
     * GET /api/sesiones/{id}/lecturas
     *
     * Query parameters:
     *   per_page  int (1–500, default 100) — max rows per page
     *   page      int (≥1, default 1)
     *   serie     1|true — return lightweight time-series payload (no pagination wrapper)
     */
    public function index(Request $request, string $id): AnonymousResourceCollection|JsonResponse
    {
        $session = Sesion::query()->find($id);
        abort_if($session === null, 404, 'Sesión no encontrada.');

        // ── Time-series mode (?serie=1) ─────────────────────────────
        // Returns only the three columns the chart needs, ordered
        // ascending by device timestamp — no pagination wrapper.
        if ($request->boolean('serie') || ($request->route()->defaults['serie'] ?? false)) {
            $rows = $session->lecturas()
                ->orderBy('timestamp_dispositivo')
                ->orderBy('id')
                ->get(['timestamp_dispositivo', 'gotas_por_min', 'vol_restante']);

            return response()->json([
                'data' => $rows->map(fn ($r) => [
                    'timestamp_dispositivo' => $r->timestamp_dispositivo,
                    'gotas_por_min' => $r->gotas_por_min,
                    'vol_restante' => $r->vol_restante,
                ]),
            ]);
        }

        // ── Paginated mode (default) ────────────────────────────────
        $perPage = min((int) $request->input('per_page', 100), 500);
        $perPage = max($perPage, 1);

        // History remains available for paused and completed sessions as well.
        $paginator = $session->lecturas()
            ->orderBy('timestamp_dispositivo')
            ->orderBy('id')
            ->paginate($perPage);

        return LecturaResource::collection($paginator);
    }
}
