<?php

namespace App\Services;

use App\Models\Paciente;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RegistroPacienteService
{
    public function registrar(array $data): Paciente
    {
        return DB::transaction(function () use ($data): Paciente {
            // Serialize registrations even when the bed has no patient row yet.
            // MQTT identifies beds globally by numero_cama, without a room field.
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(lower(?), 0))', [
                'registro-paciente:'.$data['numero_cama'],
            ]);

            $occupied = Paciente::query()
                ->whereRaw('lower(numero_cama) = lower(?)', [$data['numero_cama']])
                ->where(function ($query): void {
                    $query->where('activo', true)->orWhereHas('sesiones', function ($sessions): void {
                        $sessions->whereIn('estado', ['ACTIVA', 'PAUSADA']);
                    });
                })->lockForUpdate()->first();

            abort_if($occupied !== null, 409, 'La cama ya está ocupada por un paciente activo o una sesión en curso.');

            // Recheck under a lock: the nurse may have changed after request validation.
            $nurse = User::query()->whereKey($data['enfermera_id'])->where('rol', 'ENFERMERA')
                ->where('activo', true)->sharedLock()->first();
            if ($nurse === null) {
                throw ValidationException::withMessages([
                    'enfermera_id' => 'La enfermera indicada no existe o no está activa.',
                ]);
            }

            $patient = Paciente::create([
                'nombre' => $data['nombre'], 'numero_cama' => $data['numero_cama'],
                'sala' => $data['sala'], 'activo' => true,
            ]);
            $patient->sesiones()->create([
                'enfermera_id' => $nurse->id, 'vol_total' => $data['vol_total'],
                'modo_goteo' => $data['modo_goteo'], 'tipo_solucion' => $data['tipo_solucion'],
                'estado' => 'ACTIVA', 'inicio' => now(),
            ]);

            return $patient->load('sesionActiva.lecturas');
        });
    }
}
