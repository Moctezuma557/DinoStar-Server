<?php

namespace Tests\Feature;

use App\Models\Paciente;
use App\Models\Sesion;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AuditoriaApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_pacientes_audita_el_crud_y_los_cambios_en_la_sesion(): void
    {
        $actor = User::factory()->admin()->create();
        $nurse = User::factory()->create();
        $this->actingAs($actor);
        $patient = $this->postJson('/api/pacientes', $this->paciente($nurse) + ['usuario_id' => $nurse->id])
            ->assertCreated()->json('data');
        $url = '/api/pacientes/'.$patient['id'];

        $this->getJson('/api/pacientes')->assertOk();
        $this->getJson($url)->assertOk();
        $this->patchJson($url, ['nombre' => 'Nombre corregido'])->assertOk();
        $this->deleteJson($url)->assertConflict();
        $this->deleteJson($url, ['finalizar_sesion' => true])->assertNoContent();
        $this->getJson($url)->assertNotFound();

        $logs = DB::table('logs_auditoria')->orderBy('id')->get();
        $this->assertSame(['CREAR', 'LISTAR', 'CONSULTAR', 'ACTUALIZAR', 'BAJA_LOGICA'], $logs->pluck('accion')->all());
        $this->assertSame(['pacientes'], $logs->pluck('tabla_afectada')->unique()->values()->all());
        $this->assertSame([$actor->id], $logs->pluck('usuario_id')->unique()->values()->all());
        $this->assertSame([$patient['id'], null, $patient['id'], $patient['id'], $patient['id']], $logs->pluck('registro_id')->all());
        $this->assertNull($logs[1]->datos_nuevos);
        $this->assertNull($logs[2]->datos_nuevos);

        $created = json_decode($logs[0]->datos_nuevos, true, flags: JSON_THROW_ON_ERROR);
        $updated = json_decode($logs[3]->datos_nuevos, true, flags: JSON_THROW_ON_ERROR);
        $discharged = json_decode($logs[4]->datos_nuevos, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('Paciente B3', $created['nombre']);
        $this->assertSame($patient['sesion']['id'], $created['sesion']['id']);
        $this->assertSame($nurse->id, $created['sesion']['enfermera_id']);
        $this->assertSame('ACTIVA', $created['sesion']['estado']);
        $this->assertEquals(['nombre' => 'Nombre corregido', 'numero_cama' => 'B3-01', 'sala' => 'Sala B3', 'activo' => true], $updated);
        $this->assertFalse($discharged['activo']);
        $this->assertSame($created['sesion']['id'], $discharged['sesion']['id']);
        $this->assertSame('FINALIZADA', $discharged['sesion']['estado']);
        $this->assertSame(Sesion::findOrFail($patient['sesion']['id'])->fin->toISOString(), $discharged['sesion']['fin']);
    }

    public function test_usuarios_audita_el_crud_sin_guardar_credenciales(): void
    {
        $actor = User::factory()->admin()->create();
        $this->actingAs($actor);
        $id = $this->postJson('/api/usuarios', $this->usuario() + [
            'remember_token' => 'token-b3-ficticio',
        ])->assertCreated()->json('data.id');
        $url = '/api/usuarios/'.$id;
        $initialHash = User::findOrFail($id)->password;

        $this->getJson('/api/usuarios')->assertOk();
        $this->patchJson($url, ['password' => 'ClaveNueva-B3', 'api_token' => 'api-token-ficticio'])->assertOk();
        $this->deleteJson($url)->assertNoContent();
        $this->deleteJson($url)->assertNoContent();

        $logs = DB::table('logs_auditoria')->orderBy('id')->get();
        $this->assertSame(['CREAR', 'LISTAR', 'ACTUALIZAR', 'BAJA_LOGICA'], $logs->pluck('accion')->all());
        $this->assertSame(['usuarios'], $logs->pluck('tabla_afectada')->unique()->values()->all());
        $this->assertSame([$actor->id], $logs->pluck('usuario_id')->unique()->values()->all());
        $this->assertSame([$id, null, $id, $id], $logs->pluck('registro_id')->all());
        $this->assertNull($logs[1]->datos_nuevos);
        $expected = ['nombre' => 'Enfermera B3', 'email' => 'enfermera.b3@example.test',
            'rol' => 'ENFERMERA', 'turno' => 'MATUTINO', 'activo' => true];
        foreach ([0, 2, 3] as $index) {
            $expected['activo'] = $index !== 3;
            $this->assertEquals($expected, json_decode($logs[$index]->datos_nuevos, true, flags: JSON_THROW_ON_ERROR));
            $this->assertNull($logs[$index]->datos_anteriores);
        }
        foreach (['ClaveInicial-B3', 'ClaveNueva-B3', 'token-b3-ficticio', 'api-token-ficticio', $initialHash, User::findOrFail($id)->password] as $secret) {
            $this->assertStringNotContainsString($secret, $logs->toJson());
        }
    }

    public function test_sin_identidad_autenticada_el_actor_es_nulo_y_los_rechazos_no_se_auditan(): void
    {
        $nurse = User::factory()->create();
        $this->postJson('/api/pacientes', $this->paciente($nurse) + ['usuario_id' => $nurse->id])
            ->assertCreated();
        $this->postJson('/api/usuarios', array_replace($this->usuario(), ['email' => $nurse->email]))
            ->assertUnprocessable();
        $this->patchJson('/api/usuarios/'.$nurse->id, ['rol' => 'ADMIN'])->assertForbidden();
        $this->postJson('/api/pacientes', $this->paciente($nurse))->assertConflict();

        $this->assertDatabaseCount('logs_auditoria', 1);
        $this->assertDatabaseHas('logs_auditoria', ['tabla_afectada' => 'pacientes', 'usuario_id' => null]);
    }

    public function test_si_falla_la_auditoria_se_revierten_paciente_y_sesion(): void
    {
        $nurse = User::factory()->create();
        $patient = Paciente::factory()->create(['numero_cama' => 'B3-02']);
        $session = Sesion::factory()->create(['paciente_id' => $patient->id, 'enfermera_id' => $nurse->id]);
        $this->rechazarAuditoria();

        $this->postJson('/api/pacientes', $this->paciente($nurse))->assertInternalServerError();
        $this->patchJson('/api/pacientes/'.$patient->id, ['nombre' => 'Edición revertida'])->assertInternalServerError();
        $this->deleteJson('/api/pacientes/'.$patient->id, ['finalizar_sesion' => true])->assertInternalServerError();

        $this->assertDatabaseCount('pacientes', 1);
        $this->assertDatabaseCount('sesiones', 1);
        $this->assertSame($patient->nombre, $patient->fresh()->nombre);
        $this->assertTrue($patient->fresh()->activo);
        $this->assertSame('ACTIVA', $session->fresh()->estado);
        $this->assertNull($session->fresh()->fin);
        $this->assertDatabaseCount('logs_auditoria', 0);
    }

    public function test_si_falla_la_auditoria_se_revierten_las_mutaciones_de_usuario(): void
    {
        $user = User::factory()->create();
        $url = '/api/usuarios/'.$user->id;
        $this->rechazarAuditoria();

        $this->postJson('/api/usuarios', $this->usuario())->assertInternalServerError();
        $this->patchJson($url, ['password' => 'ClaveNueva-B3'])->assertInternalServerError();
        $this->deleteJson($url)->assertInternalServerError();

        $this->assertDatabaseCount('usuarios', 1);
        $this->assertSame($user->password, $user->fresh()->password);
        $this->assertTrue($user->fresh()->activo);
        $this->assertDatabaseCount('logs_auditoria', 0);
    }

    public function test_bajas_logicas_conservan_el_historial_y_la_fk_restringe_el_borrado_del_actor(): void
    {
        $actor = User::factory()->create();
        $target = User::factory()->create();
        $legacyId = DB::table('logs_auditoria')->insertGetId([
            'usuario_id' => $actor->id, 'accion' => 'HISTORICO', 'tabla_afectada' => 'usuarios',
            'registro_id' => $target->id, 'datos_nuevos' => '{"origen":"legado"}',
        ]);
        $legacy = DB::table('logs_auditoria')->find($legacyId);

        $this->actingAs($actor)->deleteJson('/api/usuarios/'.$target->id)->assertNoContent();
        $this->deleteJson('/api/usuarios/'.$actor->id)->assertNoContent();
        $this->assertEquals($legacy, DB::table('logs_auditoria')->find($legacyId));
        $this->assertFalse($target->fresh()->activo);
        $this->assertFalse($actor->fresh()->activo);

        try {
            DB::transaction(fn () => DB::table('usuarios')->delete($actor->id));
            $this->fail('La FK de auditoría debe proteger al actor contra el borrado físico.');
        } catch (QueryException $exception) {
            $this->assertSame('23503', $exception->errorInfo[0]);
            $this->assertStringContainsString('fk_logs_usuario', $exception->getMessage());
        }
        $this->assertDatabaseCount('logs_auditoria', 3);
    }

    private function paciente(User $nurse): array
    {
        return [
            'nombre' => 'Paciente B3', 'numero_cama' => 'B3-01', 'sala' => 'Sala B3',
            'enfermera_id' => $nurse->id, 'vol_total' => 500,
            'modo_goteo' => 'MICRO_GOTEO', 'tipo_solucion' => 'Salina 0.9%',
        ];
    }

    private function usuario(): array
    {
        return [
            'nombre' => 'Enfermera B3', 'email' => ' ENFERMERA.B3@EXAMPLE.TEST ',
            'password' => 'ClaveInicial-B3', 'rol' => 'ENFERMERA', 'turno' => 'MATUTINO',
        ];
    }

    private function rechazarAuditoria(): void
    {
        DB::statement("ALTER TABLE logs_auditoria ADD CHECK (accion = 'PRUEBA')");
    }
}
