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
        $generado = now();
        
        return Pdf::loadView('reportes.pdf', [
            'filtros' => $filtros,
            'generado' => $generado,
            'resumen' => $reportes->resumen($filtros),
            'categorias' => $reportes->alertasPorCategoria($filtros),
            'sesiones' => $reportes->sesionesHistoricas($filtros)->get(),
        ])->setPaper('a4', 'landscape')->setOptions([
            'defaultFont' => 'DejaVu Sans',
            'isFontSubsettingEnabled' => true,
            'isRemoteEnabled' => false,
            'isPhpEnabled' => false,
            'isJavascriptEnabled' => false,
       ])->download('dinostar-reporte-'.$generado->format('Ymd-His').'.pdf')->withHeaders([
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
