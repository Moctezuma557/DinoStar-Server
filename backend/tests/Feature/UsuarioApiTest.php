<?php

namespace Tests\Feature;

use App\Models\Paciente;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class UsuarioApiTest extends TestCase
{
    private ?string $database = null;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('DINOSTAR_MIGRATION_TEST') !== '1') {
            $this->markTestSkipped('Requires isolated PostgreSQL on port 55432.');
        }
        $connection = [
            'driver' => 'pgsql', 'host' => '127.0.0.1', 'port' => '55432',
            'database' => 'postgres', 'username' => 'postgres', 'password' => 'issue22-test-only',
            'charset' => 'utf8', 'prefix' => '', 'search_path' => 'public', 'sslmode' => 'prefer',
        ];
        config(['database.connections.usuarios_admin' => $connection]);
        $this->database = 'dinostar112_'.bin2hex(random_bytes(8));
        DB::connection('usuarios_admin')->statement('CREATE DATABASE "'.$this->database.'"');
        config(['database.default' => 'pgsql', 'database.connections.pgsql' => array_replace($connection, ['database' => $this->database])]);
        DB::purge('pgsql');
        $this->assertSame(0, Artisan::call('migrate', ['--force' => true, '--no-interaction' => true]));
    }

    protected function tearDown(): void
    {
        try {
            if ($this->database !== null) {
                DB::purge('pgsql');
                DB::connection('usuarios_admin')->statement('DROP DATABASE "'.$this->database.'"');
                DB::purge('usuarios_admin');
            }
        } finally {
            parent::tearDown();
        }
    }

    private function payload(array $changes = []): array
    {
        return array_replace([
            'nombre' => 'Enfermera B2', 'email' => 'enfermera.b2@example.test',
            'password' => 'Prueba-B2-123', 'rol' => 'ENFERMERA', 'turno' => 'MATUTINO',
        ], $changes);
    }

    public function test_create_normalizes_email_hashes_password_and_uses_the_auth_user_model(): void
    {
        $response = $this->postJson('/api/usuarios', $this->payload([
            'email' => ' ENFERMERA.B2@EXAMPLE.TEST ', 'id' => 999, 'remember_token' => 'secret',
        ]))->assertCreated()->assertJsonPath('data.email', 'enfermera.b2@example.test')
            ->assertJsonPath('data.activo', true)->assertJsonPath('data.rol', 'ENFERMERA')
            ->assertJsonMissingPath('data.password')->assertJsonMissingPath('data.remember_token');
        $user = User::findOrFail($response->json('data.id'));
        $this->assertNotSame(999, $user->id);
        $this->assertNotSame('Prueba-B2-123', $user->password);
        $this->assertTrue(Hash::check('Prueba-B2-123', $user->password));
        $provider = Auth::createUserProvider('users');
        $this->assertSame($user->id, $provider->retrieveByCredentials(['email' => $user->email])->id);
        $this->assertTrue($provider->validateCredentials($user, ['password' => 'Prueba-B2-123']));
        $this->assertDatabaseCount('usuarios', 1);
    }

    public static function invalidCreates(): array
    {
        return [
            'blank name' => [['nombre' => '   '], 'nombre'],
            'long name' => [['nombre' => str_repeat('a', 151)], 'nombre'],
            'array name' => [['nombre' => ['invalid']], 'nombre'],
            'invalid email' => [['email' => 'invalid'], 'email'],
            'short password' => [['password' => '1234567'], 'password'],
            'multibyte password exceeds bcrypt limit' => [['password' => str_repeat('ñ', 37)], 'password'],
            'null password' => [['password' => null], 'password'],
            'invalid role' => [['rol' => 'DOCTOR'], 'rol'],
            'missing shift' => [['turno' => null], 'turno'],
            'invalid shift' => [['turno' => 'MADRUGADA'], 'turno'],
            'invalid active' => [['activo' => 'false'], 'activo'],
        ];
    }

    #[DataProvider('invalidCreates')]
    public function test_invalid_create_returns_422_without_writes(array $changes, string $field): void
    {
        $this->postJson('/api/usuarios', $this->payload($changes))->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertDatabaseCount('usuarios', 0);
    }

    public function test_missing_fields_return_json_validation_errors_without_accept_header(): void
    {
        $this->post('/api/usuarios', [])->assertUnprocessable()->assertJsonValidationErrors(['nombre', 'email', 'password', 'rol']);
    }

    public function test_admin_creation_and_promotion_are_rejected(): void
    {
        $this->postJson('/api/usuarios', $this->payload(['rol' => 'ADMIN', 'turno' => null]))->assertForbidden();
        $this->assertDatabaseCount('usuarios', 0);
        $user = User::factory()->create();
        $this->patchJson('/api/usuarios/'.$user->id, ['nombre' => 'No guardar', 'rol' => 'ADMIN', 'turno' => null])->assertForbidden();
        $this->assertSame('ENFERMERA', $user->fresh()->rol);
        $this->assertSame($user->nombre, $user->fresh()->nombre);
    }

    public function test_duplicate_email_is_rejected_even_for_inactive_users_and_different_case(): void
    {
        User::factory()->create(['email' => 'enfermera.b2@example.test', 'activo' => false]);
        $this->postJson('/api/usuarios', $this->payload(['email' => 'ENFERMERA.B2@EXAMPLE.TEST']))
            ->assertUnprocessable()->assertJsonValidationErrors('email');
        $other = User::factory()->create();
        $this->patchJson('/api/usuarios/'.$other->id, ['email' => 'ENFERMERA.B2@EXAMPLE.TEST', 'nombre' => 'No guardar'])
            ->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->assertSame($other->email, $other->fresh()->email);
        $this->assertSame($other->nombre, $other->fresh()->nombre);
        $this->assertDatabaseCount('usuarios', 2);
    }

    public function test_list_combines_filters_and_never_exposes_passwords(): void
    {
        $target = User::factory()->create(['nombre' => 'Ana B2', 'activo' => false]);
        User::factory()->create(['nombre' => 'Ana B2', 'activo' => true]);
        User::factory()->admin()->create(['nombre' => 'Ana B2', 'activo' => false]);
        User::factory()->create(['nombre' => 'Beatriz', 'activo' => false]);
        $this->getJson('/api/usuarios?rol=ENFERMERA&activo=false&nombre=ana')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $target->id)
            ->assertJsonMissingPath('data.0.password')->assertJsonMissingPath('data.0.remember_token');
        $this->getJson('/api/usuarios?activo=0')->assertOk()->assertJsonCount(3, 'data');
        $this->getJson('/api/usuarios?activo=1')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/usuarios?activo=true')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/usuarios')->assertOk()->assertJsonCount(4, 'data');
        $this->getJson('/api/usuarios?nombre=inexistente')->assertOk()->assertExactJson(['data' => []]);
    }

    public static function invalidFilters(): array
    {
        return [['rol=DOCTOR', 'rol'], ['activo=banana', 'activo'], ['activo=2', 'activo'], ['nombre[]=Ana', 'nombre']];
    }

    #[DataProvider('invalidFilters')]
    public function test_invalid_filters_return_422(string $query, string $field): void
    {
        $this->getJson('/api/usuarios?'.$query)->assertUnprocessable()->assertJsonValidationErrors($field);
    }

    public function test_name_filter_treats_sql_wildcards_and_quotes_literally(): void
    {
        $name = "Ana %_\\' OR 1=1 --";
        $target = User::factory()->create(['nombre' => $name]);
        User::factory()->create(['nombre' => 'Ana diferente']);
        $this->getJson('/api/usuarios?'.http_build_query(['nombre' => $name]))
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $target->id);
    }

    public function test_partial_update_keeps_password_and_same_email_and_can_change_password(): void
    {
        $user = User::factory()->create();
        $hash = $user->password;
        $this->patchJson('/api/usuarios/'.$user->id, ['nombre' => 'Nombre editado', 'email' => strtoupper($user->email)])
            ->assertOk()->assertJsonPath('data.nombre', 'Nombre editado')->assertJsonPath('data.email', $user->email)
            ->assertJsonMissingPath('data.password');
        $this->assertSame($hash, $user->fresh()->password);
        $this->patchJson('/api/usuarios/'.$user->id, ['password' => 'Nueva-B2-456', 'turno' => 'NOCTURNO'])
            ->assertOk()->assertJsonPath('data.turno', 'NOCTURNO')->assertJsonMissingPath('data.password');
        $this->assertTrue(Hash::check('Nueva-B2-456', $user->fresh()->password));
        $this->assertFalse(Hash::check('password', $user->fresh()->password));
    }

    public static function invalidUpdates(): array
    {
        return [
            [[], 'usuario'], [['id' => 10], 'usuario'], [['nombre' => ' '], 'nombre'],
            [['password' => null], 'password'], [['password' => '1234567'], 'password'],
            [['turno' => null], 'turno'], [['activo' => 'false'], 'activo'],
        ];
    }

    #[DataProvider('invalidUpdates')]
    public function test_invalid_update_preserves_user(array $changes, string $field): void
    {
        $user = User::factory()->create();
        $before = $user->fresh()->getAttributes();
        $this->patchJson('/api/usuarios/'.$user->id, $changes)->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertEquals($before, $user->fresh()->getAttributes());
    }

    public static function lastAdminChanges(): array
    {
        return [['delete', []], ['patch', ['activo' => false]], ['patch', ['rol' => 'ENFERMERA', 'turno' => 'MATUTINO']]];
    }

    #[DataProvider('lastAdminChanges')]
    public function test_last_active_admin_cannot_be_deleted_deactivated_or_demoted(string $method, array $changes): void
    {
        $admin = User::factory()->admin()->create();
        User::factory()->admin()->create(['activo' => false]);
        $this->json($method, '/api/usuarios/'.$admin->id, $changes)->assertConflict();
        $this->assertTrue($admin->fresh()->activo);
        $this->assertSame('ADMIN', $admin->fresh()->rol);
    }

    public function test_with_two_admins_one_can_be_demoted_but_the_remaining_one_is_protected(): void
    {
        $first = User::factory()->admin()->create();
        $last = User::factory()->admin()->create();
        $this->patchJson('/api/usuarios/'.$first->id, ['rol' => 'ENFERMERA'])
            ->assertUnprocessable()->assertJsonValidationErrors('turno');
        $this->patchJson('/api/usuarios/'.$first->id, ['rol' => 'ENFERMERA', 'turno' => 'VESPERTINO'])
            ->assertOk()->assertJsonPath('data.rol', 'ENFERMERA');
        $this->deleteJson('/api/usuarios/'.$last->id)->assertConflict();
    }

    public function test_admin_can_be_edited_and_one_of_two_admins_can_be_deactivated(): void
    {
        $admin = User::factory()->admin()->create();
        User::factory()->admin()->create();
        $this->patchJson('/api/usuarios/'.$admin->id, ['nombre' => 'Admin editado'])
            ->assertOk()->assertJsonPath('data.turno', null);
        $this->patchJson('/api/usuarios/'.$admin->id, ['turno' => 'MATUTINO'])
            ->assertUnprocessable()->assertJsonValidationErrors('turno');
        $this->deleteJson('/api/usuarios/'.$admin->id)->assertNoContent();
        $this->assertFalse($admin->fresh()->activo);
    }

    public function test_edit_and_soft_delete_preserve_session_reading_and_alert_history(): void
    {
        $user = User::factory()->create();
        $patient = Paciente::create(['nombre' => 'Paciente B2', 'numero_cama' => 'b2-historial', 'sala' => 'QA', 'activo' => true]);
        $session = $patient->sesiones()->create(['enfermera_id' => $user->id, 'vol_total' => 500,
            'modo_goteo' => 'MICRO_GOTEO', 'estado' => 'ACTIVA', 'inicio' => now()]);
        $reading = $session->lecturas()->create(['gotas_por_min' => 19.9, 'vol_restante' => 45.2,
            'tiempo_restante_min' => 136, 'timestamp_dispositivo' => now()]);
        $alert = $session->alertas()->create(['tipo' => 'GOTEO_LENTO', 'mensaje' => 'Prueba B2', 'resuelta' => false]);
        $sessionBefore = $session->fresh()->getAttributes();
        $readingBefore = $reading->fresh()->getAttributes();
        $alertBefore = $alert->fresh()->getAttributes();
        $this->patchJson('/api/usuarios/'.$user->id, ['nombre' => 'Enfermera editada'])->assertOk();
        $this->deleteJson('/api/usuarios/'.$user->id)->assertNoContent();
        $this->deleteJson('/api/usuarios/'.$user->id)->assertNoContent();
        $this->assertDatabaseHas('usuarios', ['id' => $user->id, 'activo' => false]);
        $this->assertEquals($sessionBefore, $session->fresh()->getAttributes());
        $this->assertEquals($readingBefore, $reading->fresh()->getAttributes());
        $this->assertEquals($alertBefore, $alert->fresh()->getAttributes());
        $this->getJson('/api/usuarios?activo=true')->assertJsonCount(0, 'data');
        $this->getJson('/api/usuarios?activo=false')->assertJsonPath('data.0.id', $user->id);
        $this->patchJson('/api/usuarios/'.$user->id, ['activo' => true])->assertOk()->assertJsonPath('data.activo', true);
    }

    public function test_inactive_nurse_cannot_be_assigned_to_a_new_patient(): void
    {
        $user = User::factory()->create();
        $this->deleteJson('/api/usuarios/'.$user->id)->assertNoContent();
        $this->postJson('/api/pacientes', ['nombre' => 'Paciente B2', 'numero_cama' => 'b2-nueva', 'sala' => 'QA',
            'enfermera_id' => $user->id, 'vol_total' => 500, 'modo_goteo' => 'MICRO_GOTEO', 'tipo_solucion' => 'Salina'])
            ->assertUnprocessable()->assertJsonValidationErrors('enfermera_id');
        $this->assertDatabaseCount('pacientes', 0);
    }

    public function test_legacy_negative_id_is_supported_and_unknown_fields_cannot_change_id(): void
    {
        $user = User::factory()->create(['id' => -35001]);
        $this->patchJson('/api/usuarios/-35001', ['nombre' => 'Demo editada', 'id' => 1000])
            ->assertOk()->assertJsonPath('data.id', -35001);
        $this->deleteJson('/api/usuarios/-35001')->assertNoContent();
        $this->assertFalse($user->fresh()->activo);
    }

    public function test_missing_and_malformed_ids_return_json_404(): void
    {
        foreach (['999999', 'abc', '1.5', '999999999999999999999'] as $id) {
            $this->patchJson('/api/usuarios/'.$id, ['nombre' => 'B2'])->assertNotFound();
            $this->delete('/api/usuarios/'.$id)->assertNotFound()->assertJsonStructure(['message']);
        }
    }
}
