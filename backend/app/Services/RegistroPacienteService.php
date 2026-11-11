<?php

namespace App\Services;

use App\Models\Paciente;
use App\Models\Sesion;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RegistroPacienteService
{
    public function __construct(private readonly AuditoriaService $auditoria) {}

    public function registrar(array $data): Paciente
    {
        return DB::transaction(function () use ($data): Paciente {
            $this->reservarCama($data['numero_cama']);

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
            $session = $patient->sesiones()->create([
                'enfermera_id' => $nurse->id, 'vol_total' => $data['vol_total'],
                'modo_goteo' => $data['modo_goteo'], 'tipo_solucion' => $data['tipo_solucion'],
                'estado' => 'ACTIVA', 'inicio' => now(),
            ]);
            $this->auditar('CREAR', $patient, $session);

            return $patient->load('sesionActiva.lecturas');
        });
    }

    public function actualizar(string $id, array $data): Paciente
    {
        return DB::transaction(function () use ($id, $data): Paciente {
            $patient = $this->pacienteActivoBloqueado($id);
            if (isset($data['numero_cama']) || isset($data['sala'])) {
                $this->reservarCama($data['numero_cama'] ?? $patient->numero_cama, $patient->id);
            }
            $patient->update($data);
            $this->auditar('ACTUALIZAR', $patient);

            return $patient;
        });
    }

    public function darDeBaja(string $id, bool $finalizarSesion): void
    {
        DB::transaction(function () use ($id, $finalizarSesion): void {
            $patient = $this->pacienteActivoBloqueado($id);
            $session = $patient->sesiones()->whereIn('estado', ['ACTIVA', 'PAUSADA'])->lockForUpdate()->first();

            if ($session !== null) {
                abort_unless($finalizarSesion, 409, 'El paciente tiene una sesión en curso. Confirme con finalizar_sesion: true.');
                $session->update(['estado' => 'FINALIZADA', 'fin' => now()]);
            }

            $patient->update(['activo' => false]);
            $this->auditar('BAJA_LOGICA', $patient, $session);
        });
    }

    private function auditar(string $accion, Paciente $patient, ?Sesion $session = null): void
    {
        $data = $patient->only(['nombre', 'numero_cama', 'sala', 'activo']);
        if ($session !== null) {
            $data['sesion'] = $session->only([
                'id', 'enfermera_id', 'vol_total', 'modo_goteo', 'tipo_solucion', 'estado', 'inicio', 'fin',
            ]);
        }

        $this->auditoria->registrar($accion, $patient->getTable(), $patient->id, $data);
    }

    private function pacienteActivoBloqueado(string $id): Paciente
    {
        $patient = Paciente::query()->where('activo', true)->lockForUpdate()->find($id);
        abort_if($patient === null, 404, 'Paciente no encontrado o inactivo.');

        return $patient;
    }

    private function reservarCama(string $cama, ?int $exceptoId = null): void
    {
        // POST and PATCH must serialize even when no row exists for the target bed.
        DB::select('SELECT pg_advisory_xact_lock(hashtextextended(lower(?), 0))', ['registro-paciente:'.$cama]);
        $occupied = Paciente::query()->whereRaw('lower(numero_cama) = lower(?)', [$cama])
            ->when($exceptoId !== null, fn ($query) => $query->where('id', '!=', $exceptoId))
            ->where(function ($query): void {
                $query->where('activo', true)->orWhereHas('sesiones', fn ($sessions) => $sessions->whereIn('estado', ['ACTIVA', 'PAUSADA']));
            })->exists();

        abort_if($occupied, 409, 'La cama ya está ocupada por un paciente activo o una sesión en curso.');
    }
}
