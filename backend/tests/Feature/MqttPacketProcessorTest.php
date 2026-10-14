<?php

namespace Tests\Feature;

use App\Models\Alerta;
use App\Models\Lectura;
use App\Services\DataPacketService;
use App\Services\MqttPacketProcessor;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class MqttPacketProcessorTest extends TestCase
{
    private function packet(array $changes = []): array
    {
        return array_replace(['pacienteId' => 'cama-01', 'gotasPorMin' => 32.5,
            'tiempoRestante' => 245, 'volRestante' => 408.5,
            'modo' => 'MICRO_GOTEO', 'timestamp' => 1791200000123], $changes);
    }

    public function test_malformed_json_and_non_list_payloads_never_call_service(): void
    {
        Log::spy();
        $service = Mockery::mock(DataPacketService::class)->makePartial();
        $service->shouldNotReceive('procesar');
        $processor = new MqttPacketProcessor($service);
        foreach (['{broken', 'null', '12', '"text"', '{}', '{"0": {}}'] as $json) {
            $processor->procesar('test/topic', $json);
        }
        Log::shouldHaveReceived('warning')->times(6);
    }

    public function test_a_failed_packet_does_not_stop_the_rest_of_the_batch_or_next_message(): void
    {
        Log::spy();
        $service = Mockery::mock(DataPacketService::class)->makePartial();
        $service->shouldReceive('procesar')->once()->with($this->packet())->andThrow(new RuntimeException('database unavailable'));
        $next = $this->packet(['pacienteId' => 'cama-02']);
        $service->shouldReceive('procesar')->twice()->with($next);
        $processor = new MqttPacketProcessor($service);
        $processor->procesar('test/topic', json_encode([null, 12, [], $this->packet(), $next]));
        $processor->procesar('test/topic', json_encode([$next]));
        Log::shouldHaveReceived('warning')->times(3);
        Log::shouldHaveReceived('error')->once();
    }

    public function test_simulator_batch_persists_valid_packets_and_skips_invalid_or_inactive_ones(): void
    {
        $this->withDemoDatabase(function (): void {
            $this->assertSimulatorBatch();
        });
    }

    public function test_four_beds_in_repeated_batches_only_persist_the_two_active_sessions(): void
    {
        $this->withDemoDatabase(function (): void {
            Log::spy();
            $processor = app(MqttPacketProcessor::class);
            for ($cycle = 0; $cycle < 3; $cycle++) {
                $timestamp = 1791200000123 + $cycle * 5000;
                $processor->procesar('hospital/braquio/telemetria', json_encode([
                    $this->packet(['pacienteId' => 'cama-03', 'timestamp' => $timestamp]),
                    $this->packet(['modo' => 'MICRO_GOTEO', 'timestamp' => $timestamp]),
                    $this->packet(['pacienteId' => 'cama-04', 'timestamp' => $timestamp]),
                    $this->packet(['pacienteId' => 'cama-02', 'modo' => 'NORMAL_GOTEO', 'timestamp' => $timestamp]),
                ]));
                $this->assertSame(10 + ($cycle + 1) * 2, Lectura::count());
            }
            $this->assertSame(3, Lectura::where('id', '>', 0)->where('sesion_id', -35001)->count());
            $this->assertSame(3, Lectura::where('id', '>', 0)->where('sesion_id', -35002)->count());
            $this->assertSame(0, Alerta::count());
            Log::shouldHaveReceived('warning')->with('[DataPacketService] No hay sesión activa para: cama-03')->times(3);
            Log::shouldHaveReceived('warning')->with('[DataPacketService] No hay sesión activa para: cama-04')->times(3);
        });
    }

    public function test_four_bed_alert_batches_generate_alerts_only_for_active_sessions(): void
    {
        $this->withDemoDatabase(function (): void {
            Log::spy();
            $processor = app(MqttPacketProcessor::class);
            foreach ([[19.9, 100, ['GOTEO_LENTO']], [60.1, 100, ['GOTEO_RAPIDO']], [19.9, 45.2, ['FIN_BOLSA', 'GOTEO_LENTO']]] as [$drops, $volume, $types]) {
                $before = Alerta::max('id') ?? 0;
                $packets = [];
                for ($bed = 1; $bed <= 4; $bed++) {
                    $packets[] = $this->packet(['pacienteId' => sprintf('cama-%02d', $bed),
                        'modo' => $bed === 1 ? 'MICRO_GOTEO' : 'NORMAL_GOTEO',
                        'gotasPorMin' => $drops, 'volRestante' => $volume]);
                }
                $processor->procesar('hospital/braquio/telemetria', json_encode($packets));
                foreach ([-35001, -35002] as $sessionId) {
                    $this->assertSame($types, Alerta::where('id', '>', $before)->where('sesion_id', $sessionId)->orderBy('id')->pluck('tipo')->all());
                }
            }
            $this->assertSame(16, Lectura::count());
            $this->assertSame(8, Alerta::count());
            $this->assertSame(0, Alerta::whereNotIn('sesion_id', [-35001, -35002])->count());
        });
    }

    public function test_more_than_two_readings_per_batch_are_allowed_and_bad_modes_do_not_stop_later_packets(): void
    {
        $this->withDemoDatabase(function (): void {
            Log::spy();
            $processor = app(MqttPacketProcessor::class);
            $packets = [$this->packet(['modo' => 'NORMAL_GOTEO'])];
            for ($sample = 0; $sample < 4; $sample++) {
                $packets[] = $this->packet(['timestamp' => 1791200000123 + $sample]);
            }
            $processor->procesar('hospital/braquio/telemetria', json_encode($packets));
            $this->assertSame(14, Lectura::count());
            $this->assertSame(0, Alerta::count());
            Log::shouldHaveReceived('warning')->with('[DataPacketService] Modo incompatible con la sesión activa.', [
                'pacienteId' => 'cama-01', 'sesion_id' => -35001,
                'modo_recibido' => 'NORMAL_GOTEO', 'modo_esperado' => 'MICRO_GOTEO',
            ])->once();
        });
    }

    private function withDemoDatabase(callable $assertions): void
    {
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
        try {
            Artisan::call('migrate', ['--force' => true, '--no-interaction' => true]);
            Artisan::call('db:seed', ['--no-interaction' => true]);
            $assertions();
        } finally {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
        }
    }

    private function assertSimulatorBatch(): void
    {
        Log::spy();
        $processor = app(MqttPacketProcessor::class);
        $processor->procesar('hospital/braquio/telemetria', json_encode([
            $this->packet(),
            $this->packet(['gotasPorMin' => 'bad']),
            $this->packet(['pacienteId' => 'cama-03']),
            $this->packet(['pacienteId' => 'cama-02', 'modo' => 'NORMAL_GOTEO', 'gotasPorMin' => 19.9, 'volRestante' => 45.2]),
            $this->packet(['gotasPorMin' => 60.1]),
        ]));
        $this->assertSame(13, Lectura::count());
        $this->assertSame(3, Alerta::count());
        $this->assertSame(['FIN_BOLSA', 'GOTEO_LENTO', 'GOTEO_RAPIDO'], Alerta::orderBy('id')->pluck('tipo')->all());
        $this->assertSame([32.5, 19.9, 60.1], Lectura::where('id', '>', 0)->orderBy('id')->pluck('gotas_por_min')->all());
        $this->assertSame(0, Alerta::where('resuelta', true)->count());
        Log::shouldHaveReceived('warning')->with('[DataPacketService] No hay sesión activa para: cama-03')->once();
        Log::shouldHaveReceived('warning')->with('[MqttListen] DataPacket rechazado por validación.', Mockery::on(fn ($context) => $context['indice'] === 1))->once();
        $processor->procesar('test/topic', json_encode([$this->packet(['gotasPorMin' => 20, 'volRestante' => 50]), $this->packet(['gotasPorMin' => 60, 'volRestante' => 50])]));
        $this->assertSame(15, Lectura::count());
        $this->assertSame(3, Alerta::count());
    }
}
