<?php

namespace Tests\Feature;

use App\Models\Alerta;
use App\Models\Lectura;
use App\Models\Paciente;
use App\Models\Sesion;
use App\Models\User;
use App\Services\DataPacketService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Tests\TestCase;

class SimulatorReceptionTest extends TestCase
{
    private array $simulation;

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
        Artisan::call('migrate', ['--force' => true, '--no-interaction' => true]);
        $this->simulation = ['enfermera_id' => User::factory()->create()->id, 'vol_total' => 500];
        for ($bed = 1; $bed <= 10; $bed++) {
            Paciente::create(['nombre' => 'Paciente de prueba '.$bed,
                'numero_cama' => sprintf('cama-%02d', $bed), 'sala' => 'Pruebas', 'activo' => true]);
        }
        Log::spy();
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

    private function packet(int $bed = 1, array $changes = []): array
    {
        return array_replace(['pacienteId' => sprintf('cama-%02d', $bed), 'gotasPorMin' => 32.5,
            'tiempoRestante' => 245, 'volRestante' => 408.5,
            'modo' => $bed % 2 === 1 ? 'MICRO_GOTEO' : 'NORMAL_GOTEO',
            'timestamp' => 1791200000123], $changes);
    }

    private function receive(array $packets): void
    {
        app(DataPacketService::class)->procesarMensaje('hospital/braquio/telemetria', json_encode($packets), $this->simulation);
    }

    public function test_four_beds_activate_without_seeder_and_repeated_batches_keep_one_session_per_bed(): void
    {
        $this->assertSame(0, Sesion::count());
        $this->assertSame(0, Lectura::count());
        for ($cycle = 0; $cycle < 3; $cycle++) {
            $packets = [];
            for ($bed = 1; $bed <= 4; $bed++) {
                $packets[] = $this->packet($bed, ['timestamp' => 1791200000123 + $cycle * 5000]);
            }
            $this->receive($packets);
        }
        $this->assertSame(4, Sesion::count());
        $this->assertSame(4, Sesion::where('estado', 'ACTIVA')->count());
        $this->assertSame(12, Lectura::count());
        $this->assertSame(0, Alerta::count());
        foreach (Sesion::with('paciente', 'lecturas')->get() as $session) {
            $bed = (int) substr($session->paciente->numero_cama, -2);
            $this->assertLessThanOrEqual(4, $bed);
            $this->assertSame($this->packet($bed)['modo'], $session->modo_goteo);
            $this->assertSame($this->simulation['enfermera_id'], $session->enfermera_id);
            $this->assertSame('500.00', $session->vol_total);
            $this->assertCount(3, $session->lecturas);
            foreach ($session->lecturas->sortBy('id')->values() as $cycle => $reading) {
                $this->assertSame(32.5, $reading->gotas_por_min);
                $this->assertSame('408.50', $reading->vol_restante);
                $this->assertSame(245, $reading->tiempo_restante_min);
                $this->assertSame(1791200000123 + $cycle * 5000, (int) $reading->timestamp_dispositivo->getTimestampMs());
            }
        }
        $this->receive([$this->packet(1)]);
        $this->assertSame(4, Sesion::where('estado', 'ACTIVA')->count());
        $this->assertSame(13, Lectura::count());
    }

    public function test_ten_beds_generate_all_alerts_and_invalid_packets_do_not_activate_other_beds(): void
    {
        $packets = [null, $this->packet(1, ['modo' => 'incorrecto']), $this->packet(11)];
        for ($bed = 1; $bed <= 10; $bed++) {
            $packets[] = $this->packet($bed, ['gotasPorMin' => $bed % 2 === 1 ? 19.9 : 60.1, 'volRestante' => 45.2]);
        }
        $this->receive($packets);
        $this->assertSame(10, Sesion::where('estado', 'ACTIVA')->count());
        $this->assertSame(10, Lectura::count());
        $this->assertSame(20, Alerta::count());
        $this->assertSame(10, Alerta::where('tipo', 'FIN_BOLSA')->count());
        $this->assertSame(5, Alerta::where('tipo', 'GOTEO_LENTO')->count());
        $this->assertSame(5, Alerta::where('tipo', 'GOTEO_RAPIDO')->count());
        $this->assertSame(0, Alerta::where('resuelta', true)->count());
    }

    public function test_mode_change_keeps_previous_readings_attached_to_their_original_mode(): void
    {
        $this->receive([$this->packet(1)]);
        $original = Sesion::sole();
        $this->receive([$this->packet(1, ['modo' => 'NORMAL_GOTEO', 'timestamp' => 1791200005123])]);
        $this->assertSame('FINALIZADA', $original->fresh()->estado);
        $this->assertNotNull($original->fresh()->fin);
        $this->assertSame('MICRO_GOTEO', $original->fresh()->modo_goteo);
        $this->assertSame(1, $original->lecturas()->count());
        $this->assertSame('NORMAL_GOTEO', Sesion::where('estado', 'ACTIVA')->sole()->modo_goteo);
        $this->assertSame(2, Lectura::count());
    }

    public function test_paused_inactive_ambiguous_and_invalid_beds_are_not_activated(): void
    {
        $this->receive([$this->packet(1)]);
        Sesion::sole()->update(['estado' => 'PAUSADA']);
        Paciente::where('numero_cama', 'cama-02')->update(['activo' => false]);
        Paciente::create(['nombre' => 'Otra sala', 'numero_cama' => 'cama-03', 'sala' => 'Otra', 'activo' => true]);
        $this->receive([$this->packet(1), $this->packet(2), $this->packet(3), $this->packet(4, ['gotasPorMin' => -1]), $this->packet(5)]);
        $this->assertSame(2, Sesion::count());
        $this->assertSame(2, Lectura::count());
        $this->assertSame('cama-05', Sesion::where('estado', 'ACTIVA')->sole()->paciente->numero_cama);
    }

    public function test_failed_alert_rolls_back_new_session_and_reading_and_next_packet_still_runs(): void
    {
        Alerta::creating(function (): void {
            throw new RuntimeException('Simulated alert failure');
        });
        try {
            $this->receive([$this->packet(1, ['gotasPorMin' => 19.9]), $this->packet(2)]);
        } finally {
            Alerta::flushEventListeners();
        }
        $this->assertSame(1, Sesion::count());
        $this->assertSame('cama-02', Sesion::sole()->paciente->numero_cama);
        $this->assertSame(1, Lectura::count());
        $this->assertSame(0, Alerta::count());
    }

    public function test_explicit_simulation_configuration_is_required_and_production_is_refused(): void
    {
        app(DataPacketService::class)->procesarMensaje('test', json_encode([$this->packet()]));
        $this->assertSame(0, Sesion::count());
        $this->assertSame(1, Artisan::call('mqtt:listen', ['--simulador' => true]));
        $this->assertSame(1, Artisan::call('mqtt:listen', ['--enfermera' => $this->simulation['enfermera_id']]));
        foreach ([['vol_total' => 0], ['vol_total' => 100], ['enfermera_id' => 9999999]] as $change) {
            app(DataPacketService::class)->procesarMensaje('test', json_encode([$this->packet()]), array_replace($this->simulation, $change));
        }
        $this->app->instance('env', 'production');
        $this->assertSame(1, Artisan::call('mqtt:listen', ['--simulador' => true,
            '--enfermera' => $this->simulation['enfermera_id'], '--volumen-inicial' => 500]));
        $this->assertStringContainsString('local/testing', Artisan::output());
        $this->assertSame(0, Sesion::count());
        $this->assertSame(0, Lectura::count());
    }
}
