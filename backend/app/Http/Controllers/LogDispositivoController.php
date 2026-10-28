<?php

namespace App\Http\Controllers;

use App\Models\LogDispositivo;
use Illuminate\Http\Request;

class LogDispositivoController extends Controller
{
    public function index(Request $request)
    {
        // Verificar que el usuario tenga rol de administrador
        if ($request->user()?->rol !== 'ADMIN') {
            return response()->json([
                'message' => 'No autorizado (403). Se requiere rol de administrador.'
            ], 403);
        }

        $query = LogDispositivo::query();

        // Aplicar filtros opcionales
        if ($request->filled('tipo')) {
            $query->where('tipo', $request->tipo);
        }

        if ($request->filled('categoria')) {
            $query->where('categoria', $request->categoria);
        }

        if ($request->filled('paciente_id')) {
            $query->where('paciente_id', $request->paciente_id);
        }

        if ($request->filled('desde')) {
            $query->where('created_at', '>=', $request->desde);
        }

        if ($request->filled('hasta')) {
            $query->where('created_at', '<=', $request->hasta);
        }

        // Ordenar del más reciente al más antiguo y paginar
        $logs = $query->orderBy('created_at', 'desc')->paginate(50);

        return response()->json($logs);
    }
}