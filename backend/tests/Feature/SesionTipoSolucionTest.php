<?php

namespace Tests\Feature;

use App\Models\Sesion;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SesionTipoSolucionTest extends TestCase
{
    private const MIGRATION = '2026_10_10_000357_add_tipo_solucion_to_sesiones_table.php';

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
        $paths = [];
        foreach (glob(database_path('migrations/*.php')) as $path) {
            if (basename($path) < self::MIGRATION) {
                $paths[] = 'database/migrations/'.basename($path);
            }
        }
        $this->assertSame(0, Artisan::call('migrate', ['--path' => $paths, '--force' => true, '--no-interaction' => true]));
        $this->assertSame(0, Artisan::call('db:seed', ['--no-interaction' => true]));
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

    private function migrate(): void
    {
        $this->assertSame(0, Artisan::call('migrate', ['--force' => true, '--no-interaction' => true]));
    }

    public function test_upgrade_preserves_existing_sessions_and_is_not_reapplied(): void
    {
        $this->assertFalse(Schema::hasColumn('sesiones', 'tipo_solucion'));
        $before = DB::table('sesiones')->orderBy('id')->get();
        $this->migrate();

        $column = DB::table('information_schema.columns')
            ->where('table_schema', 'public')->where('table_name', 'sesiones')
            ->where('column_name', 'tipo_solucion')->sole();
        $this->assertSame('character varying', $column->data_type);
        $this->assertSame(255, (int) $column->character_maximum_length);
        $this->assertSame('YES', $column->is_nullable);
        $this->assertSame(2, DB::table('sesiones')->whereNull('tipo_solucion')->count());
        foreach ($before as $session) {
            $this->assertEquals($session, DB::table('sesiones')->select(array_keys((array) $session))->where('id', $session->id)->sole());
        }

        $this->migrate();
        $this->assertStringContainsString('Nothing to migrate', Artisan::output());
    }

    public function test_model_can_create_sessions_with_and_without_solution_type(): void
    {
        $this->migrate();
        $attributes = ['paciente_id' => -35003, 'enfermera_id' => -35001,
            'vol_total' => 500, 'modo_goteo' => 'NORMAL_GOTEO',
            'estado' => 'ACTIVA', 'inicio' => now(),
        ];
        $session = Sesion::create($attributes + ['tipo_solucion' => 'Solución salina 0.9%']);
        $this->assertSame('Solución salina 0.9%', $session->fresh()->tipo_solucion);
        $this->assertGreaterThan(0, $session->id);

        $legacy = Sesion::create(array_replace($attributes, ['paciente_id' => -35004]));
        $this->assertNull($legacy->fresh()->tipo_solucion);
        $this->assertSame(4, Sesion::count());
        $this->assertSame(0, Artisan::call('db:seed', ['--no-interaction' => true]));
        $this->assertSame('Solución salina 0.9%', $session->fresh()->tipo_solucion);
        $this->assertSame(4, Sesion::count());
    }

    public function test_rollback_removes_only_the_new_column_and_can_be_migrated_again(): void
    {
        $before = DB::table('sesiones')->orderBy('id')->get();
        $this->migrate();
        DB::table('sesiones')->where('id', -35001)->update(['tipo_solucion' => 'Solución de prueba']);

        $this->assertSame(0, Artisan::call('migrate:rollback', ['--step' => 1, '--force' => true, '--no-interaction' => true]));
        $this->assertFalse(Schema::hasColumn('sesiones', 'tipo_solucion'));
        $this->assertEquals($before, DB::table('sesiones')->orderBy('id')->get());
        $this->assertSame(10, DB::table('lecturas')->count());
        $this->migrate();
        $this->assertTrue(Schema::hasColumn('sesiones', 'tipo_solucion'));
    }
}
