<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Tests\TestCase;

class DemoSeederTest extends TestCase
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

    private function seedDemo(): void
    {
        $this->assertSame(0, Artisan::call('db:seed', ['--force' => true, '--no-interaction' => true]));
    }

    public function test_required_dataset_and_repeat_preserves_modified_records(): void
    {
        $this->seedDemo();
        $this->assertSame(1, DB::table('usuarios')->count());
        $this->assertSame(10, DB::table('pacientes')->count());
        $this->assertSame(2, DB::table('sesiones')->count());
        $this->assertSame(10, DB::table('lecturas')->count());
        $nurse = DB::table('usuarios')->first();
        $this->assertSame('enfermera@dinostar.com', $nurse->email);
        $this->assertSame('ENFERMERA', $nurse->rol);
        $this->assertSame('MATUTINO', $nurse->turno);
        $this->assertSame('bcrypt', password_get_info($nurse->password)['algoName']);
        $this->assertTrue(Hash::check('password', $nurse->password));
        $this->assertSame(array_map(fn ($n) => sprintf('cama-%02d', $n), range(1, 10)), DB::table('pacientes')->orderBy('numero_cama')->pluck('numero_cama')->all());
        foreach (DB::table('sesiones')->get() as $session) {
            $this->assertSame('ACTIVA', $session->estado);
            $this->assertSame($nurse->id, $session->enfermera_id);
            $this->assertSame($session->id, $session->paciente_id);
            $normal = $session->modo_goteo === 'NORMAL_GOTEO';
            $this->assertContains($session->modo_goteo, ['NORMAL_GOTEO', 'MICRO_GOTEO']);
            $this->assertEquals($normal ? 500 : 250, $session->vol_total);
            $readings = DB::table('lecturas')->where('sesion_id', $session->id)->orderBy('timestamp_dispositivo')->get();
            $this->assertCount(5, $readings);
            $previous = (float) $session->vol_total + 1;
            foreach ($readings as $reading) {
                $this->assertLessThan($previous, (float) $reading->vol_restante);
                $this->assertGreaterThanOrEqual(20, $reading->gotas_por_min);
                $this->assertLessThanOrEqual(60, $reading->gotas_por_min);
                $this->assertEquals(ceil($reading->vol_restante * ($normal ? 20 : 60) / $reading->gotas_por_min), $reading->tiempo_restante_min);
                $previous = (float) $reading->vol_restante;
            }
        }
        DB::table('sesiones')->where('id', -35001)->update(['estado' => 'PAUSADA']);
        $before = [];
        foreach (['usuarios', 'pacientes', 'sesiones', 'lecturas'] as $table) {
            $before[$table] = DB::table($table)->orderBy('id')->get()->toJson();
        }
        $this->seedDemo();
        foreach ($before as $table => $rows) {
            $this->assertSame($rows, DB::table($table)->orderBy('id')->get()->toJson());
        }
    }

    public function test_collision_rolls_back_without_touching_existing_patient(): void
    {
        DB::table('pacientes')->insert(['nombre' => 'Paciente existente', 'numero_cama' => 'cama-10', 'sala' => 'Sala A']);
        try {
            $this->seedDemo();
            $this->fail('Occupied bed must not be overwritten.');
        } catch (QueryException $exception) {
            $this->assertSame('23505', $exception->errorInfo[0]);
        }
        $this->assertSame(0, DB::table('usuarios')->count());
        $this->assertSame(1, DB::table('pacientes')->count());
        $this->assertSame('Paciente existente', DB::table('pacientes')->value('nombre'));
    }

    public function test_reserved_id_collision_is_rejected(): void
    {
        DB::table('pacientes')->insert(['id' => -35001, 'nombre' => 'Otro', 'numero_cama' => 'otra', 'sala' => 'Sala B']);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Conflicto de datos demo');
        $this->seedDemo();
    }

    public function test_demo_is_refused_in_production_even_with_force(): void
    {
        $this->app->instance('env', 'production');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('solo se permiten');
        $this->seedDemo();
    }
}
