<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AuditoriaService
{
    public function __construct(private readonly Request $request) {}

    public function registrar(string $accion, string $tabla, ?int $registroId = null, ?array $datosNuevos = null): void
    {
        $actor = $this->request->user();

        DB::table('logs_auditoria')->insert([
            'usuario_id' => $actor instanceof User ? $actor->getKey() : null,
            'accion' => $accion,
            'tabla_afectada' => $tabla,
            'registro_id' => $registroId,
            'datos_nuevos' => $datosNuevos === null ? null : json_encode($datosNuevos, JSON_THROW_ON_ERROR),
        ]);
    }
}
