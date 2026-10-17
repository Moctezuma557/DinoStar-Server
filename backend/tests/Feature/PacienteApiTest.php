<?php

namespace Tests\Feature;

use App\Models\Paciente;
use App\Models\User;
use App\Services\DataPacketService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PacienteApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('DINOSTAR_MIGRATION_TEST') !== '1') {
            $this->markTestSkipped('Requires the isolated PostgreSQL test database on port 55432.');
        }
        config(['database.default' => 'pgsql', 'database.connections.pgsql' => [
            'driver' => 'pgsql', 'host' => '127.0.0.1', 'port' => '55432',
            'database' => 'dinostar_issue22_test', 'username' => 'postgres',
            'password' => 'issue22-test-only', 'charset' => 'utf8',
            'prefix' => '', 'search_path' => 'public', 'sslmode' => 'prefer',
        ]]);
        DB::purge('pgsql');
        DB::beginTransaction();
        $this->assertSame(0, Artisan::call('migrate', ['--force' => true, '--no-interaction' => true]));
    }

    protected function tearDown(): void
    {
        if (getenv('DINOSTAR_MIGRATION_TEST') === '1') {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
        }
        parent::tearDown();
    }

    private function payload(array $changes = []): array
    {
        return array_replace([
            'nombre' => 'Paciente API', 'numero_cama' => 'cama-01', 'sala' => 'Sala A',
            'vol_total' => 500, 'modo_goteo' => 'MICRO_GOTEO', 'tipo_solucion' => 'Salina 0.9%',
            'enfermera_id' => User::factory()->create()->id,
        ], $changes);
    }

    private function patient(array $changes = [], array $session = []): Paciente
    {
        $patient = Paciente::create(array_replace([
            'nombre' => 'Paciente API', 'numero_cama' => 'cama-01', 'sala' => 'Sala A', 'activo' => true,
        ], $changes));
        $patient->sesiones()->create(array_replace([
            'enfermera_id' => User::factory()->create()->id, 'vol_total' => 500,
            'tipo_solucion' => 'Salina 0.9%', 'modo_goteo' => 'MICRO_GOTEO',
            'estado' => 'ACTIVA', 'inicio' => now(),
        ], $session));

        return $patient;
    }

    public function test_post_creates_patient_and_active_session_without_seeder_and_ignores_extra_keys(): void
    {
        $this->freezeTime();
        $payload = $this->payload(['activo' => false, 'estado' => 'FINALIZADA', 'paciente_id' => 999]);

        $response = $this->postJson('/api/pacientes', $payload);

        $response->assertCreated()->assertJsonPath('data.nombre', 'Paciente API')
            ->assertJsonPath('data.activo', true)->assertJsonPath('data.sesion.estado', 'ACTIVA')
            ->assertJsonPath('data.sesion.enfermera_id', $payload['enfermera_id'])
            ->assertJsonPath('data.sesion.vol_total', '500.00')->assertJsonPath('data.sesion.tipo_solucion', 'Salina 0.9%')
            ->assertJsonPath('data.sesion.modo_goteo', 'MICRO_GOTEO')->assertJsonPath('data.sesion.fin', null)
            ->assertJsonCount(0, 'data.sesion.lecturas');
        $id = $response->json('data.id');
        $response->assertHeader('Location', url('/api/pacientes/'.$id));
        $this->assertDatabaseHas('pacientes', ['id' => $id, 'numero_cama' => 'cama-01', 'activo' => true]);
        $this->assertDatabaseHas('sesiones', ['paciente_id' => $id, 'enfermera_id' => $payload['enfermera_id'],
            'tipo_solucion' => 'Salina 0.9%', 'estado' => 'ACTIVA']);
        $this->assertDatabaseCount('pacientes', 1);
        $this->assertDatabaseCount('sesiones', 1);
    }

    public function test_missing_fields_return_422_without_writes(): void
    {
        $this->postJson('/api/pacientes', [])->assertUnprocessable()
            ->assertJsonValidationErrors(['nombre', 'numero_cama', 'sala', 'vol_total', 'modo_goteo', 'tipo_solucion', 'enfermera_id'])
            ->assertJsonPath('errors.nombre.0', 'El campo nombre es obligatorio.');
        $this->assertDatabaseCount('pacientes', 0);
        $this->assertDatabaseCount('sesiones', 0);
    }

    public static function invalidFields(): array
    {
        return [
            'blank name' => ['nombre', '   '],
            'name too long' => ['nombre', str_repeat('a', 151)],
            'bed too long' => ['numero_cama', str_repeat('a', 21)],
            'room too long' => ['sala', str_repeat('a', 51)],
            'solution too long' => ['tipo_solucion', str_repeat('a', 256)],
            'name array' => ['nombre', ['invalid']],
            'zero volume' => ['vol_total', 0],
            'negative volume' => ['vol_total', -1],
            'volume too large' => ['vol_total', 5000.01],
            'rounded volume' => ['vol_total', 0.001],
            'nonnumeric volume' => ['vol_total', 'abc'],
            'invalid mode' => ['modo_goteo', 'RAPIDO'],
            'missing nurse' => ['enfermera_id', 999999],
            'invalid nurse' => ['enfermera_id', '1 OR 1=1'],
            'overflow nurse' => ['enfermera_id', '999999999999999999999999'],
        ];
    }

    #[DataProvider('invalidFields')]
    public function test_invalid_input_returns_422_without_partial_records(string $field, mixed $value): void
    {
        $this->postJson('/api/pacientes', $this->payload([$field => $value]))
            ->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertDatabaseCount('pacientes', 0);
        $this->assertDatabaseCount('sesiones', 0);
    }

    public function test_admin_and_inactive_nurse_cannot_be_assigned(): void
    {
        foreach ([User::factory()->admin()->create(), User::factory()->create(['activo' => false])] as $nurse) {
            $this->postJson('/api/pacientes', $this->payload(['enfermera_id' => $nurse->id]))
                ->assertUnprocessable()->assertJsonPath('errors.enfermera_id.0', 'La enfermera indicada no existe o no está activa.');
        }
        $this->assertDatabaseCount('pacientes', 0);
        $this->assertDatabaseCount('sesiones', 0);
    }

    public static function occupiedBeds(): array
    {
        return [
            'active session' => [true, 'ACTIVA'],
            'paused session' => [true, 'PAUSADA'],
            'inactive patient with running session' => [false, 'ACTIVA'],
            'active patient without session' => [true, null],
        ];
    }

    #[DataProvider('occupiedBeds')]
    public function test_occupied_bed_returns_409_even_in_another_room(bool $active, ?string $state): void
    {
        $patient = $this->patient(['activo' => $active], ['estado' => $state ?? 'ACTIVA']);
        if ($state === null) {
            $patient->sesiones()->delete();
        }

        $this->postJson('/api/pacientes', $this->payload(['numero_cama' => ' CAMA-01 ', 'sala' => 'Otra sala']))
            ->assertConflict()->assertJsonPath('message', 'La cama ya está ocupada por un paciente activo o una sesión en curso.');

        $this->assertDatabaseCount('pacientes', 1);
        $this->assertDatabaseCount('sesiones', $state === null ? 0 : 1);
        $this->assertSame($active, $patient->fresh()->activo);
    }

    public function test_vacated_bed_creates_new_patient_without_overwriting_history(): void
    {
        $old = $this->patient(['activo' => false, 'nombre' => 'Anterior'], ['estado' => 'FINALIZADA', 'fin' => now()]);

        $this->postJson('/api/pacientes', $this->payload())->assertCreated();

        $this->assertDatabaseHas('pacientes', ['id' => $old->id, 'nombre' => 'Anterior', 'activo' => false]);
        $this->assertDatabaseCount('pacientes', 2);
        $this->assertDatabaseCount('sesiones', 2);
    }

    public function test_session_failure_rolls_back_patient_creation(): void
    {
        // Force a database failure at the second insert, after the patient was written.
        DB::unprepared("CREATE FUNCTION reject_api_session() RETURNS trigger LANGUAGE plpgsql AS
            'BEGIN RAISE EXCEPTION ''session unavailable''; END;'");
        DB::unprepared('CREATE TRIGGER reject_api_session BEFORE INSERT ON sesiones FOR EACH ROW EXECUTE FUNCTION reject_api_session()');

        $this->postJson('/api/pacientes', $this->payload())->assertInternalServerError();

        $this->assertDatabaseCount('pacientes', 0);
        $this->assertDatabaseCount('sesiones', 0);
    }

    public function test_list_returns_only_active_patients_with_active_sessions_and_required_fields(): void
    {
        $visible = $this->patient();
        $this->patient(['numero_cama' => 'cama-02'], ['estado' => 'PAUSADA']);
        $this->patient(['numero_cama' => 'cama-03', 'activo' => false]);
        $this->patient(['numero_cama' => 'cama-04'], ['estado' => 'FINALIZADA', 'fin' => now()]);
        Paciente::create(['nombre' => 'Sin sesión', 'numero_cama' => 'cama-05', 'sala' => 'Sala A']);

        $this->getJson('/api/pacientes')->assertOk()->assertExactJson(['data' => [[
            'id' => $visible->id, 'nombre' => 'Paciente API', 'numero_cama' => 'cama-01', 'sala' => 'Sala A',
            'tipo_solucion' => 'Salina 0.9%', 'modo_goteo' => 'MICRO_GOTEO', 'vol_total' => '500.00',
            'enfermera_id' => $visible->sesionActiva->enfermera_id,
        ]]]);
    }

    public function test_name_and_nurse_filters_apply_to_the_same_active_session(): void
    {
        $nurse = User::factory()->create();
        $target = $this->patient(['nombre' => 'Ana Pérez'], ['enfermera_id' => $nurse->id]);
        $this->patient(['nombre' => 'Ana López', 'numero_cama' => 'cama-02']);
        $this->patient(['nombre' => 'Luis', 'numero_cama' => 'cama-03'], ['enfermera_id' => $nurse->id]);

        $this->getJson('/api/pacientes?nombre=ANA&enfermera_id='.$nurse->id)->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $target->id);
    }

    public function test_name_search_escapes_wildcards_and_sql_input(): void
    {
        $target = $this->patient(['nombre' => 'Paciente 100%_A']);
        $this->patient(['nombre' => 'Paciente 100XXA', 'numero_cama' => 'cama-02']);

        $this->getJson('/api/pacientes?'.http_build_query(['nombre' => '%_']))->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $target->id);
        $this->getJson('/api/pacientes?'.http_build_query(['nombre' => "' OR 1=1 --"]))
            ->assertOk()->assertExactJson(['data' => []]);
    }

    public function test_invalid_filters_return_422_and_no_matches_return_empty_list(): void
    {
        $this->getJson('/api/pacientes?nombre[]=x&enfermera_id=abc')->assertUnprocessable()
            ->assertJsonValidationErrors(['nombre', 'enfermera_id']);
        $this->getJson('/api/pacientes?enfermera_id=999999')->assertOk()->assertExactJson(['data' => []]);
    }

    public function test_detail_shows_current_session_readings_only_in_device_time_order(): void
    {
        $patient = $this->patient();
        $session = $patient->sesionActiva;
        $historical = $patient->sesiones()->create([
            'enfermera_id' => $session->enfermera_id, 'vol_total' => 250, 'modo_goteo' => 'NORMAL_GOTEO',
            'estado' => 'FINALIZADA', 'inicio' => now(), 'fin' => now(),
        ]);
        $attributes = ['gotas_por_min' => 32.5, 'vol_restante' => 450, 'tiempo_restante_min' => 120];
        $later = $session->lecturas()->create($attributes + ['timestamp_dispositivo' => '2026-10-14 10:00:05.123+00']);
        $earlier = $session->lecturas()->create($attributes + ['timestamp_dispositivo' => '2026-10-14 10:00:00.123+00']);
        $historical->lecturas()->create($attributes + ['timestamp_dispositivo' => '2026-10-13 10:00:00+00']);

        $this->getJson('/api/pacientes/'.$patient->id)->assertOk()
            ->assertJsonPath('data.id', $patient->id)->assertJsonPath('data.sesion.id', $session->id)
            ->assertJsonPath('data.sesion.tipo_solucion', 'Salina 0.9%')->assertJsonCount(2, 'data.sesion.lecturas')
            ->assertJsonPath('data.sesion.lecturas.0.id', $earlier->id)
            ->assertJsonPath('data.sesion.lecturas.1.id', $later->id)
            ->assertJsonPath('data.sesion.lecturas.0.timestamp_dispositivo', '2026-10-14T10:00:00.123000Z');
    }

    public function test_detail_returns_json_404_for_missing_patient_or_missing_active_session(): void
    {
        $patient = Paciente::create(['nombre' => 'Sin sesión', 'numero_cama' => 'cama-01', 'sala' => 'A']);
        $paused = $this->patient(['numero_cama' => 'cama-02'], ['estado' => 'PAUSADA']);
        $inactive = $this->patient(['numero_cama' => 'cama-03', 'activo' => false]);
        foreach ([$patient->id, $paused->id, $inactive->id, 999999] as $id) {
            $this->get('/api/pacientes/'.$id)->assertNotFound()
                ->assertJsonPath('message', 'Paciente no encontrado o sin sesión activa.');
        }
        $this->getJson('/api/pacientes/99999999999999999999999')->assertNotFound();
    }

    public function test_legacy_negative_ids_and_nullable_solution_are_readable(): void
    {
        $patient = $this->patient(['id' => -35001], ['tipo_solucion' => null]);

        $this->getJson('/api/pacientes/'.$patient->id)->assertOk()->assertJsonPath('data.sesion.tipo_solucion', null);
    }

    public function test_api_registration_accepts_mqtt_readings_without_seeder_or_simulation_mode(): void
    {
        $response = $this->postJson('/api/pacientes', $this->payload())->assertCreated();
        $id = $response->json('data.id');
        $sessionId = $response->json('data.sesion.id');

        app(DataPacketService::class)->procesarMensaje('hospital/braquio/telemetria', json_encode([[
            'pacienteId' => 'cama-01', 'gotasPorMin' => 19.9, 'volRestante' => 45.2,
            'tiempoRestante' => 136, 'modo' => 'MICRO_GOTEO', 'timestamp' => 1791950400123,
        ]]));

        $this->getJson('/api/pacientes/'.$id)->assertOk()->assertJsonPath('data.sesion.id', $sessionId)
            ->assertJsonPath('data.sesion.tipo_solucion', 'Salina 0.9%')
            ->assertJsonCount(1, 'data.sesion.lecturas')->assertJsonPath('data.sesion.lecturas.0.gotas_por_min', 19.9);
        $this->assertDatabaseCount('sesiones', 1);
        $this->assertDatabaseHas('alertas', ['sesion_id' => $sessionId, 'tipo' => 'GOTEO_LENTO', 'resuelta' => false]);
        $this->assertDatabaseHas('alertas', ['sesion_id' => $sessionId, 'tipo' => 'FIN_BOLSA', 'resuelta' => false]);
    }

    public function test_four_api_patients_receive_a_batch_without_seeders_or_replacing_sessions(): void
    {
        $nurse = User::factory()->create();
        $sessionIds = [];
        $packets = [];
        for ($bed = 1; $bed <= 4; $bed++) {
            $mode = $bed === 1 ? 'MICRO_GOTEO' : 'NORMAL_GOTEO';
            $number = sprintf('cama-%02d', $bed);
            $response = $this->postJson('/api/pacientes', [
                'nombre' => 'Prueba cama '.$bed, 'numero_cama' => $number, 'sala' => 'API',
                'vol_total' => 500, 'modo_goteo' => $mode, 'tipo_solucion' => 'Salina',
                'enfermera_id' => $nurse->id,
            ])->assertCreated();
            $sessionIds[] = $response->json('data.sesion.id');
            $packets[] = ['pacienteId' => $number, 'gotasPorMin' => 32.5, 'volRestante' => 450,
                'tiempoRestante' => 120, 'modo' => $mode, 'timestamp' => 1791950400123];
        }

        app(DataPacketService::class)->procesarMensaje('hospital/braquio/telemetria', json_encode($packets));

        $this->assertDatabaseCount('pacientes', 4);
        $this->assertDatabaseCount('sesiones', 4);
        $this->assertDatabaseCount('lecturas', 4);
        $this->assertDatabaseCount('alertas', 0);
        foreach ($sessionIds as $id) {
            $this->assertDatabaseHas('sesiones', ['id' => $id, 'estado' => 'ACTIVA', 'tipo_solucion' => 'Salina']);
            $this->assertDatabaseHas('lecturas', ['sesion_id' => $id, 'gotas_por_min' => 32.5]);
        }
    }
}
