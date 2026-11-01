<?php

namespace App\Http\Controllers;

use App\Models\LogDispositivo;
use Illuminate\Http\Request;

class LogDispositivoController extends Controller
{
    public function index(Request $request)
    {
        // Verificar que el usuario tenga rol de administrador
      //  if ($request->user()?->rol !== 'ADMIN') {
        //    return response()->json([
          //      'message' => 'No autorizado (403). Se requiere rol de administrador.'
           // ], 403);
        //}

        $logs = LogDispositivo::orderBy('created_at', 'desc')->paginate(50);
    return response()->json($logs);

        $query = LogDispositivo::query();

        // 1. FILTRADO OBLIGATORIO DE RELEVANCIA: 
        // Si no se especifica un tipo en particular, mostramos por defecto solo los logs relevantes 
        // (sesiones erróneas/inexistentes e inconsistencias de datos).
        if ($request->filled('tipo')) {
            $query->where('tipo', $request->tipo);
        } else {
            $query->whereIn('tipo', ['session_error', 'data_inconsistency', 'warning']);
        }

        // Aplicar los demás filtros opcionales
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