<?php

namespace App\Http\Controllers;

use App\Http\Requests\ExportarReporteRequest;
use App\Http\Requests\FiltrosReporteRequest;
use App\Services\ReporteQueryService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class ReporteController extends Controller
{
    /**
     * K1: KPIs del rango (total, completadas, con alertas y tiempo promedio de respuesta).
     * Solo delega en ReporteQueryService (B1): el controlador no calcula nada.
     */
    public function resumen(FiltrosReporteRequest $request, ReporteQueryService $reportes): JsonResponse
    {
        return $this->datos($reportes->resumen($request->filtros()));
    }

    /** K1: GOTEO_LENTO, FIN_BOLSA y GOTEO_RAPIDO con cantidad, porcentaje y promedio de resolución. */
    public function alertasCategoria(FiltrosReporteRequest $request, ReporteQueryService $reportes): JsonResponse
    {
        return $this->datos($reportes->alertasPorCategoria($request->filtros()));
    }

    public function exportar(ExportarReporteRequest $request, ReporteQueryService $reportes): Response
    {
        $filtros = $request->filtros();
        $pdf = Pdf::loadView('reportes.pdf', [
            'reporte' => $reportes->paraPdf($filtros),
            'filtros' => $filtros,
            'generadoEn' => now()->format('Y-m-d H:i:s'),
        ])->setPaper('letter', 'portrait');

        $contenido = $pdf->output();

        return response($contenido, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="reporte-infusion.pdf"',
            'Content-Length' => strlen($contenido),
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * JSON_PRESERVE_ZERO_FRACTION mantiene 50.0 como número decimal (no 50) y así el
     * contrato de B1 con V1 conserva el tipo; los promedios sin datos salen como null.
     *
     * @param  array<mixed>  $datos
     */
    private function datos(array $datos): JsonResponse
    {
        return response()->json(['data' => $datos], 200, [], JSON_PRESERVE_ZERO_FRACTION);
    }
}
