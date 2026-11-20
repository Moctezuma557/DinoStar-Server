<?php

namespace Tests\Feature;

use App\Models\Paciente;
use App\Models\Sesion;
use App\Models\User;
use App\Services\DataPacketService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SesionLecturaApiTest extends TestCase
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

    private function createSession(array $changes = []): Sesion
    {
        $patient = Paciente::create([
            'nombre' => 'Historial API', 'numero_cama' => 'historial-76', 'sala' => 'Pruebas', 'activo' => true,
        ]);

        return $patient->sesiones()->create(array_replace([
            'enfermera_id' => User::factory()->create()->id, 'vol_total' => 500,
            'tipo_solucion' => 'Salina 0.9%', 'modo_goteo' => 'MICRO_GOTEO',
            'estado' => 'ACTIVA', 'inicio' => '2026-10-20 10:00:00+00',
        ], $changes));
    }

    private function reading(Sesion $session, string $timestamp, float $drops = 32.5): void
    {
        $session->lecturas()->create([
            'gotas_por_min' => $drops, 'vol_restante' => 450.2,
            'tiempo_restante_min' => 830, 'timestamp_dispositivo' => $timestamp,
        ]);
    }

    public function test_history_is_scoped_to_session_and_sorted_by_device_time_with_stable_ties(): void
    {
        $session = $this->createSession();
        $other = $session->paciente->sesiones()->create([
            'enfermera_id' => $session->enfermera_id, 'vol_total' => 250,
            'modo_goteo' => 'NORMAL_GOTEO', 'estado' => 'FINALIZADA',
            'inicio' => '2026-10-19 10:00:00+00', 'fin' => '2026-10-19 11:00:00+00',
        ]);
        // Insert out of time order, including microseconds and equivalent time zones.
        $this->reading($session, '2026-10-20 10:00:00.123457+00', 60.1);
        $this->reading($session, '2026-10-20 04:00:00.123456-06', 19.9);
        $this->reading($session, '2026-10-20 10:00:00.123456+00', 32.5);
        $this->reading($other, '2026-10-19 10:00:00+00', 99.9);

        $response = $this->getJson('/api/sesiones/'.$session->id.'/lecturas')->assertOk();
        $response->assertExactJson(['data' => [
            ['gotas_por_min' => 19.9, 'vol_restante' => '450.20', 'tiempo_restante_min' => 830,
                'timestamp_dispositivo' => '2026-10-20T10:00:00.123456Z'],
            ['gotas_por_min' => 32.5, 'vol_restante' => '450.20', 'tiempo_restante_min' => 830,
                'timestamp_dispositivo' => '2026-10-20T10:00:00.123456Z'],
            ['gotas_por_min' => 60.1, 'vol_restante' => '450.20', 'tiempo_restante_min' => 830,
                'timestamp_dispositivo' => '2026-10-20T10:00:00.123457Z'],
        ]]);
        $response->assertJsonPath('data.0.gotas_por_min', 19.9)
            ->assertJsonPath('data.1.gotas_por_min', 32.5)->assertJsonPath('data.2.gotas_por_min', 60.1);
        $this->assertDatabaseCount('lecturas', 4);
        $this->assertDatabaseCount('sesiones', 2);
    }

    public static function sessionStates(): array
    {
        return [['ACTIVA'], ['PAUSADA'], ['FINALIZADA']];
    }

    #[DataProvider('sessionStates')]
    public function test_history_is_available_in_every_session_state_even_for_inactive_patient(string $state): void
    {
        $session = $this->createSession(['estado' => $state, 'fin' => $state === 'FINALIZADA' ? '2026-10-20 11:00:00+00' : null]);
        $session->paciente->update(['activo' => false]);
        $this->reading($session, '2026-10-20 10:00:05+00');

        $this->getJson('/api/sesiones/'.$session->id.'/lecturas')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.gotas_por_min', 32.5);
        $this->assertSame($state, $session->fresh()->estado);
        $this->assertFalse($session->paciente->fresh()->activo);
    }

    public function test_existing_session_without_readings_returns_an_empty_array(): void
    {
        $session = $this->createSession();
        $this->getJson('/api/sesiones/'.$session->id.'/lecturas')->assertOk()->assertExactJson(['data' => []]);
        $this->assertDatabaseCount('lecturas', 0);
    }

    public function test_legacy_negative_session_id_is_supported(): void
    {
        $session = $this->createSession();
        $session->forceFill(['id' => -35001])->save();
        $this->reading($session, '2026-10-20 10:00:05+00');
        $this->getJson('/api/sesiones/-35001/lecturas')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_missing_session_returns_json_404_even_without_accept_header(): void
    {
        $this->get('/api/sesiones/999999999999999999/lecturas')->assertNotFound()
            ->assertHeader('Content-Type', 'application/json')->assertJsonPath('message', 'Sesión no encontrada.');
    }

    public static function invalidIds(): array
    {
        return [['abc'], ['1.5'], ['999999999999999999999999'], ['1%20OR%201=1']];
    }

    #[DataProvider('invalidIds')]
    public function test_invalid_session_id_returns_404_instead_of_a_database_error(string $id): void
    {
        $this->getJson('/api/sesiones/'.$id.'/lecturas')->assertNotFound();
    }

    public function test_reads_packets_from_existing_service_without_seeder_or_new_session(): void
    {
        $session = $this->createSession();
        app(DataPacketService::class)->procesarMensaje('hospital/braquio/telemetria', json_encode([[
            'pacienteId' => $session->paciente->numero_cama, 'gotasPorMin' => 19.9,
            'volRestante' => 45.2, 'tiempoRestante' => 136, 'modo' => 'MICRO_GOTEO',
            'timestamp' => 1792483200123,
        ]]));

        $this->getJson('/api/sesiones/'.$session->id.'/lecturas')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.gotas_por_min', 19.9)->assertJsonPath('data.0.vol_restante', '45.20');
        $this->assertDatabaseCount('sesiones', 1);
        $this->assertDatabaseCount('lecturas', 1);
        $this->assertDatabaseCount('alertas', 2);
    }

    // ── K3: Pagination tests ────────────────────────────────────────

    public function test_per_page_limits_the_number_of_rows_returned(): void
    {
        $session = $this->createSession();
        for ($i = 1; $i <= 15; $i++) {
            $this->reading($session, "2026-10-20 10:00:{$i}+00", $i);
        }

        $response = $this->getJson('/api/sesiones/'.$session->id.'/lecturas?per_page=10')
            ->assertOk();

        $response->assertJsonCount(10, 'data');
        // Pagination metadata must be present
        $response->assertJsonStructure(['data', 'links', 'meta']);
        $response->assertJsonPath('meta.per_page', 10);
        $response->assertJsonPath('meta.total', 15);
        $response->assertJsonPath('meta.last_page', 2);
    }

    public function test_page_parameter_returns_correct_offset(): void
    {
        $session = $this->createSession();
        for ($i = 1; $i <= 15; $i++) {
            $sec = str_pad($i, 2, '0', STR_PAD_LEFT);
            $this->reading($session, "2026-10-20 10:00:{$sec}+00", (float) $i);
        }

        $page2 = $this->getJson('/api/sesiones/'.$session->id.'/lecturas?per_page=10&page=2')
            ->assertOk();

        $page2->assertJsonCount(5, 'data');
        // First item on page 2 should be reading #11
        $page2->assertJsonPath('data.0.gotas_por_min', 11.0);
        $page2->assertJsonPath('meta.current_page', 2);
    }

    public function test_default_per_page_is_100(): void
    {
        $session = $this->createSession();
        for ($i = 1; $i <= 105; $i++) {
            $sec = str_pad($i % 60, 2, '0', STR_PAD_LEFT);
            $min = str_pad(intdiv($i, 60), 2, '0', STR_PAD_LEFT);
            $this->reading($session, "2026-10-20 10:{$min}:{$sec}+00", (float) $i);
        }

        $response = $this->getJson('/api/sesiones/'.$session->id.'/lecturas')
            ->assertOk();

        $response->assertJsonCount(100, 'data');
        $response->assertJsonPath('meta.per_page', 100);
        $response->assertJsonPath('meta.total', 105);
    }

    public function test_per_page_is_clamped_to_max_500(): void
    {
        $session = $this->createSession();
        $this->reading($session, '2026-10-20 10:00:01+00');

        $response = $this->getJson('/api/sesiones/'.$session->id.'/lecturas?per_page=9999')
            ->assertOk();

        $response->assertJsonPath('meta.per_page', 500);
    }

    public function test_per_page_minimum_is_1(): void
    {
        $session = $this->createSession();
        $this->reading($session, '2026-10-20 10:00:01+00');

        $response = $this->getJson('/api/sesiones/'.$session->id.'/lecturas?per_page=0')
            ->assertOk();

        $response->assertJsonPath('meta.per_page', 1);
        $response->assertJsonCount(1, 'data');
    }

    public function test_no_parameters_returns_paginated_data_with_default_wrapper(): void
    {
        $session = $this->createSession();
        $this->reading($session, '2026-10-20 10:00:01+00', 32.5);

        $response = $this->getJson('/api/sesiones/'.$session->id.'/lecturas')
            ->assertOk();

        // The frontend reads from `data` key — paginated response has it
        $response->assertJsonStructure(['data', 'links', 'meta']);
        $response->assertJsonPath('data.0.gotas_por_min', 32.5);
        $response->assertJsonPath('data.0.vol_restante', '450.20');
        $response->assertJsonPath('data.0.tiempo_restante_min', 830);
    }

    // ── K3: Time-series tests ───────────────────────────────────────

    public function test_serie_query_param_returns_chronological_time_series(): void
    {
        $session = $this->createSession();
        // Insert out of order
        $this->reading($session, '2026-10-20 10:00:03+00', 30.0);
        $this->reading($session, '2026-10-20 10:00:01+00', 10.0);
        $this->reading($session, '2026-10-20 10:00:02+00', 20.0);

        $response = $this->getJson('/api/sesiones/'.$session->id.'/lecturas?serie=1')
            ->assertOk();

        $data = $response->json('data');
        $this->assertCount(3, $data);

        // Must be ordered ascending by timestamp_dispositivo
        $this->assertSame(10.0, $data[0]['gotas_por_min']);
        $this->assertSame(20.0, $data[1]['gotas_por_min']);
        $this->assertSame(30.0, $data[2]['gotas_por_min']);

        // Only the three chart columns
        $this->assertArrayHasKey('timestamp_dispositivo', $data[0]);
        $this->assertArrayHasKey('gotas_por_min', $data[0]);
        $this->assertArrayHasKey('vol_restante', $data[0]);
        $this->assertArrayNotHasKey('tiempo_restante_min', $data[0]);

        // No pagination wrapper (no meta/links)
        $response->assertJsonMissingPath('meta');
        $response->assertJsonMissingPath('links');
    }

    public function test_serie_dedicated_route_works(): void
    {
        $session = $this->createSession();
        $this->reading($session, '2026-10-20 10:00:02+00', 20.0);
        $this->reading($session, '2026-10-20 10:00:01+00', 10.0);

        $response = $this->getJson('/api/sesiones/'.$session->id.'/lecturas/serie')
            ->assertOk();

        $data = $response->json('data');
        $this->assertCount(2, $data);
        $this->assertSame(10.0, $data[0]['gotas_por_min']);
        $this->assertSame(20.0, $data[1]['gotas_por_min']);

        // No pagination meta
        $response->assertJsonMissingPath('meta');
    }

    public function test_serie_on_missing_session_returns_404(): void
    {
        $this->getJson('/api/sesiones/999999999999999999/lecturas?serie=1')
            ->assertNotFound()
            ->assertJsonPath('message', 'Sesión no encontrada.');
    }

    public function test_serie_with_empty_session_returns_empty_data_array(): void
    {
        $session = $this->createSession();

        $response = $this->getJson('/api/sesiones/'.$session->id.'/lecturas?serie=1')
            ->assertOk();

        $this->assertSame([], $response->json('data'));
    }
}
