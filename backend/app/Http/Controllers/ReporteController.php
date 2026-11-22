<?php

namespace App\Http\Controllers;

use App\Http\Requests\ExportarReporteRequest;
use App\Services\ReporteQueryService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;

class ReporteController extends Controller
{
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
