<?php

namespace Tests\Feature;

use App\Events\NuevaLectura;
use App\Models\Alerta;
use App\Models\Paciente;
use App\Models\Sesion;
use App\Models\User;
use App\Services\DataPacketService;
use Illuminate\Broadcasting\BroadcastEvent;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class NuevaLecturaBroadcastTest extends TestCase
{
    private ?string $database = null;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('DINOSTAR_MIGRATION_TEST') !== '1') {
            $this->markTestSkipped('Requires isolated PostgreSQL on port 55432.');
        }
        // Real root commits are necessary to test afterCommit; use a disposable database.
        $connection = [
            'driver' => 'pgsql', 'host' => '127.0.0.1', 'port' => '55432',
            'database' => 'postgres', 'username' => 'postgres', 'password' => 'issue22-test-only',
            'charset' => 'utf8', 'prefix' => '', 'search_path' => 'public', 'sslmode' => 'prefer',
        ];
        config(['database.connections.broadcast_admin' => $connection]);
        $this->database = 'dinostar77_'.bin2hex(random_bytes(8));
        DB::connection('broadcast_admin')->statement('CREATE DATABASE "'.$this->database.'"');
        config(['database.default' => 'pgsql', 'database.connections.pgsql' => array_replace($connection, ['database' => $this->database])]);
        DB::purge('pgsql');
        $this->assertSame(0, Artisan::call('migrate', ['--force' => true, '--no-interaction' => true]));
        Queue::fake([BroadcastEvent::class]);
    }

    protected function tearDown(): void
    {
        try {
            if ($this->database !== null) {
                while (DB::transactionLevel() > 0) {
                    DB::rollBack();
                }
                DB::purge('pgsql');
                DB::connection('broadcast_admin')->statement('DROP DATABASE "'.$this->database.'"');
                DB::purge('broadcast_admin');
            }
        } finally {
            parent::tearDown();
        }
    }

    private function sessionFixture(string $bed = 'broadcast-77', string $mode = 'MICRO_GOTEO'): Sesion
    {
        $patient = Paciente::create(['nombre' => 'Broadcast test', 'numero_cama' => $bed, 'sala' => 'Pruebas', 'activo' => true]);

        return $patient->sesiones()->create([
            'enfermera_id' => User::factory()->create()->id, 'vol_total' => 500,
            'modo_goteo' => $mode, 'estado' => 'ACTIVA', 'inicio' => now(),
        ]);
    }

    private function packet(array $changes = []): array
    {
        return array_replace([
            'pacienteId' => 'broadcast-77', 'gotasPorMin' => 32.5, 'volRestante' => 450.0,
            'tiempoRestante' => 830, 'modo' => 'MICRO_GOTEO', 'timestamp' => 1792483200123,
        ], $changes);
    }

    public static function alerts(): array
    {
        return [
            'normal' => [32.5, 450.0, []],
            'slow' => [19.9, 45.2, ['FIN_BOLSA', 'GOTEO_LENTO']],
            'fast' => [60.1, 40.0, ['FIN_BOLSA', 'GOTEO_RAPIDO']],
            'lower boundary' => [20.0, 50.0, []],
            'upper boundary' => [60.0, 50.0, []],
        ];
    }

    #[DataProvider('alerts')]
    public function test_broadcast_after_root_commit_contains_saved_reading_and_only_its_alerts(float $drops, float $volume, array $types): void
    {
        $session = $this->sessionFixture();
        $session->alertas()->create(['tipo' => 'GOTEO_RAPIDO', 'mensaje' => 'Alerta anterior', 'resuelta' => false]);
        DB::beginTransaction();
        app(DataPacketService::class)->procesar($this->packet(['gotasPorMin' => $drops, 'volRestante' => $volume]));
        $reading = $session->lecturas()->sole();
        $this->assertSame(count($types) + 1, $session->alertas()->count());
        Queue::assertNothingPushed();
        DB::commit();

        Queue::assertPushed(BroadcastEvent::class, 1);
        $event = Queue::pushed(BroadcastEvent::class)->sole()->event;
        $this->assertInstanceOf(NuevaLectura::class, $event);
        $this->assertSame('pacientes', $event->broadcastOn()[0]->name);
        $this->assertSame('nueva.lectura', $event->broadcastAs());
        $this->assertSame('broadcast-77', $event->pacienteId);
        $this->assertSame($drops, $event->gotasPorMin);
        $this->assertSame($volume, $event->volRestante);
        $this->assertSame(830, $event->tiempoRestante);
        $this->assertSame('MICRO_GOTEO', $event->modo);
        $this->assertSame(1792483200123, $event->timestamp);
        $this->assertSame($types, $event->alertas);
        $this->assertSame($reading->id, $event->lecturaId);
        $this->assertSame($session->id, $event->sesionId);
        $this->assertSame(0, DB::transactionLevel());
    }

    public function test_rollback_discards_reading_alerts_and_broadcast(): void
    {
        $session = $this->sessionFixture();
        DB::beginTransaction();
        app(DataPacketService::class)->procesar($this->packet(['gotasPorMin' => 19.9, 'volRestante' => 45.2, 'modo' => 'NORMAL_GOTEO']));
        $this->assertSame('NORMAL_GOTEO', $session->fresh()->modo_goteo);
        Queue::assertNothingPushed();
        DB::rollBack();
        Queue::assertNothingPushed();
        $this->assertSame('MICRO_GOTEO', $session->fresh()->modo_goteo);
        $this->assertDatabaseCount('lecturas', 0);
        $this->assertDatabaseCount('alertas', 0);

        // A later commit must not publish a callback discarded by the rollback.
        app(DataPacketService::class)->procesar($this->packet());
        Queue::assertPushed(BroadcastEvent::class, 1);
        $event = Queue::pushed(BroadcastEvent::class)->sole()->event;
        $this->assertSame($session->lecturas()->sole()->id, $event->lecturaId);
        $this->assertSame([], $event->alertas);
    }

    public function test_two_batches_for_ten_beds_keep_readings_alerts_and_events_in_their_own_sessions(): void
    {
        $sessions = [];
        $batches = [[], []];
        for ($bedNumber = 1; $bedNumber <= 10; $bedNumber++) {
            $bed = sprintf('broadcast-%02d', $bedNumber);
            $mode = $bedNumber % 2 === 1 ? 'MICRO_GOTEO' : 'NORMAL_GOTEO';
            $sessions[$bed] = $this->sessionFixture($bed, $mode);
            $batches[0][] = $this->packet(['pacienteId' => $bed, 'modo' => $mode]);
            $batches[1][] = $this->packet([
                'pacienteId' => $bed, 'modo' => $mode === 'MICRO_GOTEO' ? 'NORMAL_GOTEO' : 'MICRO_GOTEO',
                'gotasPorMin' => $bedNumber % 2 === 1 ? 19.9 : 60.1, 'volRestante' => 40.0,
                'timestamp' => 1792483201123,
            ]);
        }

        DB::beginTransaction();
        foreach ($batches as $packets) {
            app(DataPacketService::class)->procesarMensaje('hospital/braquio/telemetria', json_encode($packets, JSON_THROW_ON_ERROR));
        }
        Queue::assertNothingPushed();
        DB::commit();

        Queue::assertPushed(BroadcastEvent::class, 20);
        $this->assertDatabaseCount('sesiones', 10);
        $this->assertDatabaseCount('lecturas', 20);
        $this->assertDatabaseCount('alertas', 20);
        $events = Queue::pushed(BroadcastEvent::class)->map(fn ($job) => $job->event);
        $this->assertCount(20, $events->pluck('lecturaId')->unique());
        foreach ($sessions as $bed => $session) {
            $bedEvents = $events->where('pacienteId', $bed)->values();
            $readings = $session->lecturas()->orderBy('timestamp_dispositivo')->get();
            $this->assertCount(2, $bedEvents);
            $this->assertCount(2, $readings);
            foreach ($bedEvents as $index => $event) {
                $this->assertSame($session->id, $event->sesionId);
                $this->assertSame($readings[$index]->id, $event->lecturaId);
                $this->assertSame($readings[$index]->gotas_por_min, (float) $event->gotasPorMin);
                $this->assertSame((float) $readings[$index]->vol_restante, (float) $event->volRestante);
                $this->assertSame((int) $readings[$index]->timestamp_dispositivo->getTimestampMs(), $event->timestamp);
            }
            $this->assertSame($session->modo_goteo, $bedEvents[0]->modo);
            $this->assertSame([], $bedEvents[0]->alertas);
            $this->assertNotSame($bedEvents[0]->modo, $bedEvents[1]->modo);
            $this->assertSame($session->fresh()->modo_goteo, $bedEvents[1]->modo);
            $expectedAlerts = ['FIN_BOLSA', $bedEvents[1]->gotasPorMin < 20 ? 'GOTEO_LENTO' : 'GOTEO_RAPIDO'];
            $this->assertSame($expectedAlerts, $bedEvents[1]->alertas);
            $this->assertSame($expectedAlerts, $session->alertas()->orderBy('id')->pluck('tipo')->all());
        }
    }

    public static function modeChanges(): array
    {
        return [
            'micro to normal' => ['MICRO_GOTEO', 'NORMAL_GOTEO'],
            'normal to micro' => ['NORMAL_GOTEO', 'MICRO_GOTEO'],
        ];
    }

    #[DataProvider('modeChanges')]
    public function test_mode_change_preserves_existing_readings_and_broadcasts_the_received_mode(string $oldMode, string $newMode): void
    {
        $session = $this->sessionFixture(mode: $oldMode);
        app(DataPacketService::class)->procesar($this->packet(['modo' => $oldMode]));
        $original = $session->lecturas()->sole();
        $before = $original->getRawOriginal();
        app(DataPacketService::class)->procesar($this->packet(['modo' => $newMode, 'timestamp' => 1792483201123]));

        $this->assertSame($before, $original->fresh()->getRawOriginal());
        $this->assertDatabaseCount('sesiones', 1);
        $this->assertSame('ACTIVA', $session->fresh()->estado);
        $this->assertSame($newMode, $session->fresh()->modo_goteo);
        $this->assertSame(2, $session->lecturas()->count());
        $this->assertDatabaseCount('alertas', 0);
        Queue::assertPushed(BroadcastEvent::class, 2);
        $events = Queue::pushed(BroadcastEvent::class)->map(fn ($job) => $job->event)->values();
        $this->assertSame($oldMode, $events[0]->modo);
        $this->assertSame($newMode, $events[1]->modo);
        $this->assertSame($session->id, $events[1]->sesionId);
        $this->assertSame($session->lecturas()->orderByDesc('id')->firstOrFail()->id, $events[1]->lecturaId);
        $this->assertSame([], $events[1]->alertas);
    }

    public function test_alert_failure_rolls_back_mode_reading_and_alerts_without_broadcast_and_next_packet_succeeds(): void
    {
        $session = $this->sessionFixture();
        Alerta::creating(function (Alerta $alert): void {
            if ($alert->tipo === 'GOTEO_LENTO') {
                throw new RuntimeException('Simulated second alert failure');
            }
        });
        try {
            app(DataPacketService::class)->procesar($this->packet([
                'modo' => 'NORMAL_GOTEO', 'gotasPorMin' => 19.9, 'volRestante' => 40.0,
            ]));
            $this->fail('Expected alert persistence failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Simulated second alert failure', $exception->getMessage());
        } finally {
            Alerta::flushEventListeners();
        }
        $this->assertSame('MICRO_GOTEO', $session->fresh()->modo_goteo);
        $this->assertDatabaseCount('lecturas', 0);
        $this->assertDatabaseCount('alertas', 0);
        Queue::assertNothingPushed();

        app(DataPacketService::class)->procesar($this->packet(['modo' => 'NORMAL_GOTEO']));
        Queue::assertPushed(BroadcastEvent::class, 1);
        $event = Queue::pushed(BroadcastEvent::class)->sole()->event;
        $this->assertSame('NORMAL_GOTEO', $session->fresh()->modo_goteo);
        $this->assertSame('NORMAL_GOTEO', $event->modo);
        $this->assertSame($session->lecturas()->sole()->id, $event->lecturaId);
        $this->assertSame([], $event->alertas);
    }

    public function test_no_broadcast_for_missing_or_paused_session(): void
    {
        app(DataPacketService::class)->procesar($this->packet());
        $this->sessionFixture()->update(['estado' => 'PAUSADA']);
        app(DataPacketService::class)->procesar($this->packet());
        Queue::assertNothingPushed();
        $this->assertDatabaseCount('lecturas', 0);
    }

    public function test_invalid_packet_cannot_save_or_broadcast(): void
    {
        $this->sessionFixture();
        try {
            app(DataPacketService::class)->procesar($this->packet(['gotasPorMin' => -1]));
            $this->fail('Expected validation rejection.');
        } catch (ValidationException) {
            Queue::assertNothingPushed();
            $this->assertDatabaseCount('lecturas', 0);
        }
    }

    public function test_original_event_constructor_remains_compatible(): void
    {
        $event = new NuevaLectura('cama-01', 32.5, 450, 830, 'MICRO_GOTEO', 1792483200123);
        $this->assertSame([], $event->alertas);
        $this->assertNull($event->lecturaId);
        $this->assertNull($event->sesionId);
    }
}
