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
     *   per_page|limit  int (1–500, default 100) — max rows per page
     *   page            int (≥1) — omit to auto-select the last page (most recent readings)
     *   serie           1|true — return lightweight time-series payload (no pagination wrapper)
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
        // Accept both per_page and limit (limit wins as fallback for per_page).
        $raw = $request->input('per_page', $request->input('limit'));
        $perPage = $raw !== null && is_numeric($raw) ? (int) $raw : 100;
        $perPage = max(1, min($perPage, 500));

        // History remains available for paused and completed sessions as well.
        $query = $session->lecturas()
            ->orderBy('timestamp_dispositivo')
            ->orderBy('id');

        // Without an explicit page, jump to the LAST page so the frontend
        // always sees the most recent readings (the live panel reads the
        // last item of the array as the current reading).
        $page = $request->integer('page') ?: null;
        if ($page === null) {
            $total = (clone $query)->count();
            $page = max(1, (int) ceil($total / $perPage));
        }

        $paginator = $query->paginate($perPage, ['*'], 'page', $page);

        return LecturaResource::collection($paginator);
    }
}
