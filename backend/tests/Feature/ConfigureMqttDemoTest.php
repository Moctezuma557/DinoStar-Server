<?php

namespace Tests\Feature;

use App\Models\Sesion;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ConfigureMqttDemoTest extends TestCase
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
        Artisan::call('db:seed', ['--no-interaction' => true]);
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

    public function test_legacy_modes_are_explicitly_corrected_without_rewriting_history(): void
    {
        DB::table('sesiones')->where('id', -35001)->update(['modo_goteo' => 'NORMAL_GOTEO']);
        DB::table('sesiones')->where('id', -35002)->update(['modo_goteo' => 'MICRO_GOTEO']);
        DB::table('alertas')->insert(['sesion_id' => -35001, 'tipo' => 'GOTEO_LENTO', 'mensaje' => 'Prueba histórica', 'resuelta' => false]);
        $before = $this->snapshot();

        $this->assertSame(0, Artisan::call('db:seed', ['--no-interaction' => true]));
        $this->assertSame($before, $this->snapshot());
        $this->assertSame(0, Artisan::call('demo:configurar-mqtt', ['--dry-run' => true]));
        $this->assertStringContainsString('Solo consulta', Artisan::output());
        $this->assertSame($before, $this->snapshot());

        $this->assertSame(0, Artisan::call('demo:configurar-mqtt'));
        $after = $this->snapshot();
        foreach (['pacientes', 'lecturas', 'alertas'] as $table) {
            $this->assertSame($before[$table], $after[$table]);
        }
        foreach (DB::table('sesiones')->get() as $session) {
            $this->assertSame($session->id === -35001 ? 'MICRO_GOTEO' : 'NORMAL_GOTEO', $session->modo_goteo);
            $previous = collect(json_decode($before['sesiones']))->firstWhere('id', $session->id);
            foreach (['paciente_id', 'enfermera_id', 'estado', 'vol_total', 'inicio', 'fin', 'tipo_solucion', 'created_at'] as $field) {
                $this->assertSame($previous->{$field}, $session->{$field});
            }
        }
        $this->assertSame(0, Artisan::call('db:seed', ['--no-interaction' => true]));
        $this->assertSame(0, Artisan::call('demo:configurar-mqtt'));
        $this->assertSame($after, $this->snapshot());
    }

    public function test_extra_active_session_blocks_configuration_without_changing_any_rows(): void
    {
        Sesion::create(['paciente_id' => -35003, 'enfermera_id' => -35001,
            'vol_total' => 500, 'modo_goteo' => 'NORMAL_GOTEO', 'estado' => 'ACTIVA', 'inicio' => now()]);
        $before = $this->snapshot();
        $this->assertSame(1, Artisan::call('demo:configurar-mqtt'));
        $this->assertStringContainsString('Hay otras sesiones activas', Artisan::output());
        $this->assertSame($before, $this->snapshot());
    }

    public function test_invalid_second_demo_session_prevents_partial_updates(): void
    {
        DB::table('sesiones')->where('id', -35001)->update(['modo_goteo' => 'NORMAL_GOTEO']);
        DB::table('sesiones')->where('id', -35002)->update(['estado' => 'FINALIZADA', 'fin' => now()]);
        $before = $this->snapshot();
        $this->assertSame(1, Artisan::call('demo:configurar-mqtt'));
        $this->assertStringContainsString('cama-02', Artisan::output());
        $this->assertSame($before, $this->snapshot());
    }

    public function test_configuration_is_refused_in_production(): void
    {
        $this->app->instance('env', 'production');
        $before = $this->snapshot();
        $this->assertSame(1, Artisan::call('demo:configurar-mqtt'));
        $this->assertStringContainsString('local/testing', Artisan::output());
        $this->assertSame($before, $this->snapshot());
    }

    private function snapshot(): array
    {
        $rows = [];
        foreach (['pacientes', 'sesiones', 'lecturas', 'alertas'] as $table) {
            $rows[$table] = DB::table($table)->orderBy('id')->get()->toJson();
        }

        return $rows;
    }
}
