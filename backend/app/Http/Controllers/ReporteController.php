<?php

namespace App\Http\Controllers;

use App\Http\Requests\ExportarReporteRequest;
use App\Http\Requests\FiltrosReporteRequest;
use App\Http\Requests\ListarSesionesReporteRequest;
use App\Http\Resources\AuditoriaAlertaResource;
use App\Http\Resources\ReporteCollection;
use App\Http\Resources\SesionReporteResource;
use App\Models\Sesion;
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

    /**
     * K2: sesiones históricas paginadas (más recientes primero, decisión 4 de B1).
     * Respuesta estándar de Laravel: `data`, `links` y `meta` (current_page, per_page, total, last_page...).
     */
    public function sesiones(ListarSesionesReporteRequest $request, ReporteQueryService $reportes): JsonResponse
    {
        ['page' => $pagina, 'per_page' => $porPagina] = $request->paginacion();

        $sesiones = $reportes->sesionesHistoricas($request->filtros())
            ->paginate($porPagina, ['*'], 'page', $pagina)
            ->withQueryString();

        return (new ReporteCollection($sesiones, SesionReporteResource::class))->response();
    }

    /** K2: auditoría de las alertas de una sesión; 404 si la sesión no existe. */
    public function alertasSesion(int $id, ReporteQueryService $reportes): JsonResponse
    {
        if (! Sesion::whereKey($id)->exists()) {
            return response()->json(['message' => 'Sesión no encontrada.'], Response::HTTP_NOT_FOUND);
        }

        return (new ReporteCollection($reportes->auditoriaSesion($id)->get(), AuditoriaAlertaResource::class))->response();
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

    /** Envuelve en `data` y conserva los decimales (50.0 y no 50) para que el frontend reciba siempre un float. */
    private function datos(array $datos): JsonResponse
    {
        return response()->json(['data' => $datos], Response::HTTP_OK, [], JSON_PRESERVE_ZERO_FRACTION);
    }
}
