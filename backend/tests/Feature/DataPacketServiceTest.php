<?php

namespace Tests\Feature;

use App\Models\Alerta;
use App\Models\Lectura;
use App\Models\Paciente;
use App\Models\Sesion;
use App\Services\DataPacketService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\TestCase;

class DataPacketServiceTest extends TestCase
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
        Artisan::call('migrate', ['--force' => true, '--no-interaction' => true]);
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

    private function packet(array $changes = []): array
    {
        return array_replace(['pacienteId' => 'cama-01', 'gotasPorMin' => 32.5,
            'tiempoRestante' => 245, 'volRestante' => 408.5,
            'modo' => 'NORMAL_GOTEO', 'timestamp' => 1726185600123], $changes);
    }

    private function demo(): void
    {
        $this->assertSame(0, Artisan::call('db:seed', ['--no-interaction' => true]));
    }

    public function test_normal_packet_preserves_decimals_milliseconds_and_relations(): void
    {
        $this->demo();
        app(DataPacketService::class)->procesar($this->packet());
        $reading = Lectura::where('id', '>', 0)->sole();
        $this->assertSame(32.5, $reading->gotas_por_min);
        $this->assertSame('408.50', $reading->vol_restante);
        $this->assertSame(245, $reading->tiempo_restante_min);
        $this->assertSame(1726185600123, (int) $reading->timestamp_dispositivo->getTimestampMs());
        $this->assertNotNull($reading->created_at);
        $this->assertSame('cama-01', $reading->sesion->paciente->numero_cama);
        $this->assertSame('enfermera@dinostar.com', $reading->sesion->enfermera->email);
        $this->assertSame(11, Lectura::count());
        $this->assertSame(0, Alerta::count());
    }

    public function test_alert_conditions_and_exact_boundaries(): void
    {
        $this->demo();
        foreach ([
            [32.5, 49.9, ['FIN_BOLSA']],
            [19.9, 100, ['GOTEO_LENTO']],
            [60.1, 100, ['GOTEO_RAPIDO']],
            [12.3, 45.2, ['FIN_BOLSA', 'GOTEO_LENTO']],
            [85.1, 45.2, ['FIN_BOLSA', 'GOTEO_RAPIDO']],
            [15, 50, ['GOTEO_LENTO']], [80, 50, ['GOTEO_RAPIDO']],
            [20, 50, []], [60, 50, []],
        ] as [$drops, $volume, $types]) {
            $lastId = Alerta::max('id') ?? 0;
            app(DataPacketService::class)->procesar($this->packet(['gotasPorMin' => $drops, 'volRestante' => $volume]));
            $alerts = Alerta::where('id', '>', $lastId)->orderBy('id')->get();
            $this->assertSame($types, $alerts->pluck('tipo')->all());
            foreach ($alerts as $alert) {
                $this->assertFalse($alert->resuelta);
                $this->assertSame(-35001, $alert->sesion_id);
                $this->assertNotEmpty($alert->mensaje);
            }
        }
        $this->assertSame(19, Lectura::count());
    }

    public function test_missing_paused_or_finished_session_writes_nothing_and_warns(): void
    {
        $this->demo();
        Log::spy();
        app(DataPacketService::class)->procesar($this->packet(['pacienteId' => 'inexistente']));
        app(DataPacketService::class)->procesar($this->packet(['pacienteId' => 'cama-03']));
        Sesion::whereKey(-35001)->update(['estado' => 'PAUSADA']);
        app(DataPacketService::class)->procesar($this->packet());
        Sesion::whereKey(-35001)->update(['estado' => 'FINALIZADA', 'fin' => now()]);
        app(DataPacketService::class)->procesar($this->packet());
        $this->assertSame(10, Lectura::count());
        $this->assertSame(0, Alerta::count());
        Log::shouldHaveReceived('warning')->times(4);
        Log::shouldNotHaveReceived('info');
    }

    public function test_ambiguous_bed_and_mismatched_mode_are_not_assigned_arbitrarily(): void
    {
        $this->demo();
        Log::spy();
        app(DataPacketService::class)->procesar($this->packet(['modo' => 'MICRO_GOTEO']));
        Paciente::create(['nombre' => 'Otro paciente', 'numero_cama' => 'cama-01', 'sala' => 'Sala B', 'activo' => true]);
        app(DataPacketService::class)->procesar($this->packet());
        $this->assertSame(10, Lectura::count());
        $this->assertSame(0, Alerta::count());
        Log::shouldHaveReceived('warning')->twice();
    }

    public function test_invalid_packets_do_not_write(): void
    {
        $this->demo();
        foreach ([
            ['gotasPorMin' => -1], ['gotasPorMin' => 201], ['gotasPorMin' => 'bad'],
            ['volRestante' => -1], ['volRestante' => 5001],
            ['tiempoRestante' => 1.5], ['timestamp' => -1],
            ['modo' => 'invalid'], ['pacienteId' => ' '], ['timestamp' => null],
        ] as $changes) {
            try {
                app(DataPacketService::class)->procesar($this->packet($changes));
                $this->fail('Invalid packet was accepted.');
            } catch (ValidationException $exception) {
                $this->assertNotEmpty($exception->errors());
            }
        }
        $this->assertSame(10, Lectura::count());
        $this->assertSame(0, Alerta::count());
    }

    public function test_alert_failure_rolls_back_reading_and_does_not_log_success(): void
    {
        $this->demo();
        Log::spy();
        Alerta::creating(function (): void {
            throw new RuntimeException('Simulated alert failure');
        });
        try {
            app(DataPacketService::class)->procesar($this->packet(['volRestante' => 10]));
            $this->fail('Expected failure');
        } catch (RuntimeException $exception) {
            $this->assertSame('Simulated alert failure', $exception->getMessage());
        } finally {
            Alerta::flushEventListeners();
        }
        $this->assertSame(10, Lectura::count());
        $this->assertSame(0, Alerta::count());
        Log::shouldNotHaveReceived('info');
    }

    public function test_decimal_migration_preserves_existing_rows_and_refuses_lossy_rollback(): void
    {
        $migration = require database_path('migrations/2026_09_28_161939_allow_decimal_gotas_por_min_in_lecturas.php');
        $migration->down();
        $this->demo();
        $before = DB::table('lecturas')->orderBy('id')->get();
        $migration->up();
        $after = DB::table('lecturas')->orderBy('id')->get();
        $this->assertEquals($before, $after);
        app(DataPacketService::class)->procesar($this->packet());
        try {
            $migration->down();
            $this->fail('Decimal data must not be rounded.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Rollback bloqueado', $exception->getMessage());
        }
        $this->assertSame(32.5, Lectura::where('id', '>', 0)->sole()->gotas_por_min);
    }
}
