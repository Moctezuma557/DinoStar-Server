<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AuditoriaK3SmokeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        $_SERVER['AUTH_PROTEGER_RUTAS'] = 'true';

        parent::setUp();
    }

    protected function tearDown(): void
    {
        try {
            parent::tearDown();
        } finally {
            unset($_SERVER['AUTH_PROTEGER_RUTAS']);
        }
    }

    public function test_login_real_atribuye_cada_mutacion_y_respeta_los_permisos(): void
    {
        $admin = User::factory()->admin()->create(['password' => 'Clave-Admin-B3']);
        $nurse = User::factory()->create(['password' => 'Clave-Nurse-B3']);
        $nurseToken = $this->login($nurse, 'Clave-Nurse-B3');
        $adminToken = $this->login($admin, 'Clave-Admin-B3');
        $asAdmin = ['Authorization' => 'Bearer '.$adminToken];
        $asNurse = ['Authorization' => 'Bearer '.$nurseToken];

        $this->postJson('/api/usuarios', [])->assertUnauthorized();
        $this->postJson('/api/usuarios', [], $asNurse)->assertForbidden();
        $this->assertDatabaseCount('logs_auditoria', 0);
        $this->app['auth']->forgetGuards();

        $patient = $this->postJson('/api/pacientes', [
            'nombre' => 'Paciente K3', 'numero_cama' => 'K3-01', 'sala' => 'A',
            'enfermera_id' => $nurse->id, 'vol_total' => 500,
            'modo_goteo' => 'MICRO_GOTEO', 'tipo_solucion' => 'Salina 0.9%',
        ], $asNurse)->assertCreated()->json('data.id');
        $this->patchJson('/api/pacientes/'.$patient, ['sala' => 'B'], $asNurse)->assertOk();
        $this->deleteJson('/api/pacientes/'.$patient, ['finalizar_sesion' => true], $asNurse)->assertNoContent();
        $this->app['auth']->forgetGuards();

        $user = $this->postJson('/api/usuarios', [
            'nombre' => 'Cuenta K3', 'email' => 'cuenta.k3@example.test',
            'password' => 'Clave-Cuenta-B3', 'rol' => 'ENFERMERA', 'turno' => 'MATUTINO',
            'usuario_id' => $nurse->id,
        ], $asAdmin)->assertCreated()->json('data.id');
        $target = User::findOrFail($user);
        $targetToken = $this->login($target, 'Clave-Cuenta-B3');
        $this->app['auth']->forgetGuards();
        $this->patchJson('/api/usuarios/'.$user, ['password' => 'Clave-Nueva-B3'], $asAdmin)->assertOk();
        $this->assertSame(0, $target->tokens()->count());
        $this->deleteJson('/api/usuarios/'.$user, [], $asAdmin)->assertNoContent();

        $logs = DB::table('logs_auditoria')->orderBy('id')->get();
        $actions = ['CREAR', 'ACTUALIZAR', 'BAJA_LOGICA'];
        $this->assertSame([...$actions, ...$actions], $logs->pluck('accion')->all());
        $this->assertSame([$nurse->id, $nurse->id, $nurse->id, $admin->id, $admin->id, $admin->id], $logs->pluck('usuario_id')->all());
        $this->assertSame([$patient, $patient, $patient, $user, $user, $user], $logs->pluck('registro_id')->all());
        foreach ([$adminToken, $nurseToken, $targetToken, $target->password, 'Clave-Cuenta-B3', 'Clave-Nueva-B3'] as $secret) {
            $this->assertStringNotContainsString($secret, $logs->toJson());
        }
    }

    public function test_fallo_de_auditoria_revierte_el_cambio_de_clave_y_la_revocacion(): void
    {
        $admin = User::factory()->admin()->create(['password' => 'Clave-Admin-B3']);
        $target = User::factory()->create(['password' => 'Clave-Cuenta-B3']);
        $adminToken = $this->login($admin, 'Clave-Admin-B3');
        $this->login($target, 'Clave-Cuenta-B3');
        $this->assertSame(1, $target->tokens()->count());

        DB::statement("ALTER TABLE logs_auditoria ADD CONSTRAINT chk_logs_prueba_k3 CHECK (accion = 'PRUEBA')");
        $this->withoutExceptionHandling();

        try {
            $this->patchJson('/api/usuarios/'.$target->id, ['password' => 'Clave-Nueva-B3'], [
                'Authorization' => 'Bearer '.$adminToken,
            ]);
            $this->fail('PostgreSQL debía rechazar el evento de auditoría.');
        } catch (QueryException $exception) {
            $this->assertSame('23514', $exception->errorInfo[0]);
            $this->assertStringContainsString('chk_logs_prueba_k3', $exception->getMessage());
        }

        $this->assertSame($target->password, $target->fresh()->password);
        $this->assertSame(1, $target->tokens()->count());
        $this->assertDatabaseCount('logs_auditoria', 0);
    }

    private function login(User $user, string $password): string
    {
        return $this->postJson('/api/login', ['email' => $user->email, 'password' => $password])
            ->assertOk()->json('data.token');
    }
}
