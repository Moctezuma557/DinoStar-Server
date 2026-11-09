<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class GestionUsuarioService
{
    public function crear(array $data): User
    {
        abort_if($data['rol'] === 'ADMIN', 403, 'Por ahora los administradores se crean únicamente por seeder o base de datos.');

        return $this->guardar(function () use ($data): User {
            $this->validarTurno($data['rol'], $data['turno'] ?? null);
            $this->validarEmail($data['email']);

            return User::create(array_replace(['activo' => true], $data));
        });
    }

    public function actualizar(string $id, array $data): User
    {
        return $this->guardar(function () use ($id, $data): User {
            $this->bloquearAdministradores();
            $user = User::query()->lockForUpdate()->find($id);
            abort_if($user === null, 404, 'Usuario no encontrado.');
            $role = $data['rol'] ?? $user->rol;
            $active = $data['activo'] ?? $user->activo;
            abort_if($user->rol !== 'ADMIN' && $role === 'ADMIN', 403,
                'Por ahora no se permite promover usuarios a ADMIN por API.');
            $this->protegerUltimoAdministrador($user, $role, $active);
            $this->validarTurno($role, array_key_exists('turno', $data) ? $data['turno'] : $user->turno);
            if (isset($data['email'])) {
                $this->validarEmail($data['email'], $user->id);
            }
            $user->fill($data)->save();

            return $user;
        });
    }

    public function darDeBaja(string $id): void
    {
        DB::transaction(function () use ($id): void {
            $this->bloquearAdministradores();
            $user = User::query()->lockForUpdate()->find($id);
            abort_if($user === null, 404, 'Usuario no encontrado.');
            $this->protegerUltimoAdministrador($user, $user->rol, false);
            if ($user->activo) {
                $user->update(['activo' => false]);
            }
        });
    }

    private function bloquearAdministradores(): void
    {
        // Serialize API edits/deactivations before counting admins, including different IDs.
        DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['usuarios:administradores-activos']);
    }

    private function protegerUltimoAdministrador(User $user, string $role, bool $active): void
    {
        if ($user->rol === 'ADMIN' && $user->activo && ($role !== 'ADMIN' || ! $active)) {
            abort_unless(User::query()->where('rol', 'ADMIN')->where('activo', true)
                ->where('id', '<>', $user->id)->exists(), 409,
                'No se puede desactivar ni cambiar el rol del último administrador activo.');
        }
    }

    private function validarTurno(string $role, ?string $shift): void
    {
        if ($role === 'ENFERMERA' && ! in_array($shift, ['MATUTINO', 'VESPERTINO', 'NOCTURNO'], true)) {
            throw ValidationException::withMessages(['turno' => 'El turno es obligatorio para una enfermera.']);
        }
        if ($role === 'ADMIN' && $shift !== null) {
            throw ValidationException::withMessages(['turno' => 'Un administrador debe tener turno nulo.']);
        }
    }

    private function validarEmail(string $email, ?int $exceptId = null): void
    {
        $query = User::query()->whereRaw('lower(email) = ?', [$email]);
        if ($exceptId !== null) {
            $query->where('id', '<>', $exceptId);
        }
        if ($query->exists()) {
            throw ValidationException::withMessages(['email' => 'El correo electrónico ya está registrado.']);
        }
    }

    private function guardar(callable $operation): User
    {
        try {
            return DB::transaction($operation);
        } catch (QueryException $exception) {
            // A concurrent insert/update can pass validation; the unique index is authoritative.
            if (($exception->errorInfo[0] ?? null) === '23505'
                && str_contains($exception->getMessage(), 'uq_usuarios_email')) {
                throw ValidationException::withMessages(['email' => 'El correo electrónico ya está registrado.']);
            }
            throw $exception;
        }
    }
}
