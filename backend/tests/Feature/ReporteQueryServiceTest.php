<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\ReporteQueryService;
use App\Support\DomainSchema;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class ReporteQueryServiceTest extends TestCase
{
    private const MIGRATION = '2026_11_19_144249_add_reportes_indexes.php';

    private ?string $database = null;

    private ReporteQueryService $reportes;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('DINOSTAR_MIGRATION_TEST') !== '1') {
            $this->markTestSkipped('Requires the isolated PostgreSQL test instance on port 55432.');
        }
        $connection = [
            'driver' => 'pgsql', 'host' => '127.0.0.1', 'port' => '55432',
            'database' => 'postgres', 'username' => 'postgres', 'password' => 'issue22-test-only',
            'charset' => 'utf8', 'prefix' => '', 'search_path' => 'public', 'sslmode' => 'prefer',
        ];
        config(['database.connections.reportes_admin' => $connection]);
        $this->database = 'dinostar128_'.bin2hex(random_bytes(8));
        DB::connection('reportes_admin')->statement('CREATE DATABASE "'.$this->database.'"');
        config(['database.default' => 'pgsql', 'database.connections.pgsql' => array_replace($connection, ['database' => $this->database])]);
        DB::purge('pgsql');
        $this->reportes = app(ReporteQueryService::class);
        $this->travelTo(CarbonImmutable::parse('2026-11-19 12:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        try {
            if ($this->database !== null) {
                DB::purge('pgsql');
                DB::connection('reportes_admin')->statement('DROP DATABASE "'.$this->database.'"');
                DB::purge('reportes_admin');
            }
        } finally {
            parent::tearDown();
        }
    }

    public function test_seeded_totals_and_empty_categories_match_direct_sql_with_exact_json_keys(): void
    {
        $this->seedDemo();
        $filtros = ['desde' => '2026-11-19', 'hasta' => '2026-11-19'];
        $resultado = $this->reportes->resumen($filtros);
        $directo = DB::selectOne(<<<'SQL'
            SELECT COUNT(*) AS total, COUNT(*) FILTER (WHERE estado = 'FINALIZADA') AS completadas
            FROM sesiones WHERE inicio >= '2026-11-19T00:00:00Z' AND inicio < '2026-11-20T00:00:00Z'
            SQL);
        $this->assertSame([
            'total_sesiones' => (int) $directo->total,
            'sesiones_completadas' => ['cantidad' => (int) $directo->completadas, 'porcentaje' => 0.0],
            'sesiones_con_alertas' => 0,
            'tiempo_promedio_respuesta' => null,
        ], $resultado);
        $this->assertGreaterThan(0, $resultado['total_sesiones']);
        $this->assertCount((int) $directo->total, $this->reportes->sesionesHistoricas($filtros)->get());
        $this->assertSame($this->categoriasVacias(), $this->reportes->alertasPorCategoria($filtros));
        $this->assertSame([], $this->reportes->auditoriaSesion(-35001)->get()->all());
        $this->assertSame($resultado, json_decode(json_encode($resultado, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR), true));
    }

    public function test_counts_are_not_multiplied_by_readings_or_multiple_attentions(): void
    {
        $this->escenario();
        $filtros = $this->dia();
        $resultado = $this->reportes->resumen($filtros);
        $directo = DB::selectOne(<<<'SQL'
            SELECT
                (SELECT COUNT(*) FROM sesiones
                 WHERE inicio >= '2026-11-18T00:00:00Z' AND inicio < '2026-11-19T00:00:00Z') AS total,
                (SELECT COUNT(*) FROM sesiones WHERE estado = 'FINALIZADA'
                 AND inicio >= '2026-11-18T00:00:00Z' AND inicio < '2026-11-19T00:00:00Z') AS completadas,
                (SELECT COUNT(DISTINCT sesion_id) FROM alertas
                 WHERE created_at >= '2026-11-18T00:00:00Z' AND created_at < '2026-11-19T00:00:00Z') AS con_alertas,
                (SELECT AVG(EXTRACT(EPOCH FROM (t.created_at - a.created_at)))
                 FROM atenciones_alerta t JOIN alertas a ON a.id = t.alerta_id
                 WHERE t.resolvio = TRUE AND a.created_at >= '2026-11-18T00:00:00Z'
                 AND a.created_at < '2026-11-19T00:00:00Z') AS respuesta
            SQL);
        $this->assertSame(3, (int) $directo->total);
        $this->assertSame((int) $directo->total, $resultado['total_sesiones']);
        $this->assertSame((int) $directo->completadas, $resultado['sesiones_completadas']['cantidad']);
        $this->assertSame(66.67, $resultado['sesiones_completadas']['porcentaje']);
        $this->assertSame((int) $directo->con_alertas, $resultado['sesiones_con_alertas']);
        $this->assertSame(14470.0, $resultado['tiempo_promedio_respuesta']);
        $this->assertSame((float) $directo->respuesta, $resultado['tiempo_promedio_respuesta']);
        $this->assertSame([
            ['tipo' => 'GOTEO_LENTO', 'cantidad' => 2, 'porcentaje' => 40.0, 'tiempo_promedio_resolucion' => 180.0],
            ['tipo' => 'FIN_BOLSA', 'cantidad' => 0, 'porcentaje' => 0.0, 'tiempo_promedio_resolucion' => null],
            ['tipo' => 'GOTEO_RAPIDO', 'cantidad' => 2, 'porcentaje' => 40.0, 'tiempo_promedio_resolucion' => 21615.0],
        ], $this->reportes->alertasPorCategoria($filtros));
        $conteo = DB::selectOne("SELECT COUNT(*) AS total FROM alertas WHERE created_at >= '2026-11-18T00:00:00Z' AND created_at < '2026-11-19T00:00:00Z'");
        $this->assertSame(5, (int) $conteo->total);
        foreach ($this->reportes->alertasPorCategoria($filtros) as $categoria) {
            $sql = DB::selectOne(<<<'SQL'
                SELECT COUNT(*) AS cantidad FROM alertas
                WHERE tipo = ? AND created_at >= '2026-11-18T00:00:00Z'
                    AND created_at < '2026-11-19T00:00:00Z'
                SQL, [$categoria['tipo']]);
            $this->assertSame((int) $sql->cantidad, $categoria['cantidad']);
        }
        $this->assertSame(10, $this->reportes->resumen()['total_sesiones']);
    }

    public function test_alert_counts_and_resolution_averages_use_alert_creation_date_independently_of_session_start(): void
    {
        $this->escenario();
        $antigua = $this->reportes->resumen($this->dia() + ['paciente_id' => -35003]);
        $this->assertSame(0, $antigua['total_sesiones']);
        $this->assertSame(1, $antigua['sesiones_con_alertas']);
        $this->assertSame(43200.0, $antigua['tiempo_promedio_respuesta']);
        $this->assertSame(0.0, $antigua['sesiones_completadas']['porcentaje']);
        $this->assertSame(43200.0, $this->reportes->alertasPorCategoria($this->dia() + ['paciente_id' => -35003])[2]['tiempo_promedio_resolucion']);

        $categorias = $this->reportes->alertasPorCategoria($this->dia() + ['paciente_id' => -35002]);
        $this->assertSame(0, $categorias[1]['cantidad']);
        $this->assertNull($categorias[1]['tiempo_promedio_resolucion']);

        $fuera = $this->reportes->resumen($this->dia() + ['paciente_id' => -35005]);
        $this->assertSame(1, $fuera['total_sesiones']);
        $this->assertSame(0, $fuera['sesiones_con_alertas']);
        $this->assertNull($fuera['tiempo_promedio_respuesta']);
    }

    #[DataProvider('filtros')]
    public function test_filters_apply_to_every_query_and_keep_inactive_history(array $filtro, int $sesiones, int $alertas, ?float $respuesta, array $ids): void
    {
        $this->escenario();
        $filtros = $this->dia() + $filtro;
        $resultado = $this->reportes->resumen($filtros);
        $this->assertSame($sesiones, $resultado['total_sesiones']);
        $this->assertSame($alertas, $resultado['sesiones_con_alertas']);
        $this->assertSame($respuesta, $resultado['tiempo_promedio_respuesta']);
        $this->assertSame($ids, $this->reportes->sesionesHistoricas($filtros)->pluck('s.id')->all());
        $categorias = $this->reportes->alertasPorCategoria($filtros);
        $this->assertCount(3, $categorias);
        if ($sesiones === 0 && $alertas === 0) {
            $this->assertSame($this->categoriasVacias(), $categorias);
            $this->assertSame([], $this->reportes->auditoriaSesion(-35001, $filtros)->get()->all());
        }
    }

    public static function filtros(): array
    {
        return [
            'patient with legacy negative ID' => [['paciente_id' => '-35001'], 1, 1, 180.0, [-35001]],
            'room' => [['sala' => 'Sala B'], 1, 1, 30.0, [-35002]],
            'assigned nurse differs from attending nurse' => [['enfermera_id' => -12801], 1, 1, 30.0, [-35002]],
            'all dimensions' => [['paciente_id' => -35002, 'sala' => 'Sala B', 'enfermera_id' => -12801], 1, 1, 30.0, [-35002]],
            'inactive patient and nurse history' => [['paciente_id' => -35005, 'enfermera_id' => -35001], 1, 0, null, [-35005]],
            'unknown patient' => [['paciente_id' => 999999], 0, 0, null, []],
            'contradictory dimensions' => [['paciente_id' => -35001, 'sala' => 'Sala B'], 0, 0, null, []],
            'SQL input is literal' => [['sala' => "Sala A' OR 1=1 --"], 0, 0, null, []],
        ];
    }

    public function test_history_is_paginated_without_duplicates_and_keeps_nullable_legacy_fields(): void
    {
        $this->escenario();
        $query = $this->reportes->sesionesHistoricas($this->dia());
        $this->assertInstanceOf(Builder::class, $query);
        $pagina = $query->paginate(2, ['*'], 'page', 1);
        $this->assertSame(3, $pagina->total());
        $this->assertSame([-35002, -35005], collect($pagina->items())->pluck('id')->all());
        $rows = $this->reportes->sesionesHistoricas($this->dia())->get()->keyBy('id');
        $this->assertSame(2, $rows[-35001]->numero_alertas);
        $this->assertSame(2, $rows[-35002]->numero_alertas);
        $this->assertSame(1, $rows[-35005]->numero_alertas);
        $this->assertSame(3600.0, $rows[-35001]->duracion_segundos);
        $this->assertNull($rows[-35002]->duracion_segundos);
        $this->assertNull($rows[-35002]->fin);
        $this->assertNull($rows[-35002]->tipo_solucion);
        $this->assertSame('Salina 0.9%', $rows[-35001]->tipo_solucion);
        $this->assertSame('Enfermera Demo', $rows[-35001]->enfermera);
        $this->assertSame('Paciente 01', $rows[-35001]->paciente);
        $this->assertSame('Sala A', $rows[-35001]->sala);
        $this->assertSame('cama-01', $rows[-35001]->numero_cama);
        $this->assertSame('500.00', $rows[-35001]->vol_total);
        $this->assertSame('FINALIZADA', $rows[-35001]->estado);
        $this->assertSame([-35002, -35005, -35001], $this->reportes->sesionesHistoricas($this->dia())->cursor()->pluck('id')->all());
        $this->assertArrayNotHasKey('password', (array) $rows[-35001]);
    }

    public function test_session_audit_keeps_unattended_alerts_and_orders_every_attention(): void
    {
        $this->escenario();
        $auditoria = $this->reportes->auditoriaSesion(-35001, $this->dia())->get();
        $this->assertSame([-128001, -128001, -128002], $auditoria->pluck('id')->all());
        $this->assertSame([60.0, 180.0, null], $auditoria->pluck('tiempo_respuesta')->all());
        $this->assertSame(['Enfermera Demo', 'Enfermera Demo', null], $auditoria->pluck('atendida_por')->all());
        $this->assertSame([-35001, -35001, null], $auditoria->pluck('atendida_por_id')->all());
        $this->assertSame([false, true, null], $auditoria->pluck('resolvio')->all());
        $this->assertSame('Alerta de prueba', $auditoria[0]->descripcion);
        $this->assertSame('GOTEO_LENTO', $auditoria[0]->tipo);
        $this->assertNotNull($auditoria[0]->hora);
        $this->assertNull($auditoria[2]->atencion_id);
        $this->assertSame([], $this->reportes->auditoriaSesion(999999)->get()->all());
        $this->assertSame([], $this->reportes->auditoriaSesion(-35001, ['sala' => 'Sala B'])->get()->all());
    }

    public function test_non_resolving_attention_keeps_average_null_and_immediate_resolution_is_zero(): void
    {
        $this->seedDemo();
        $this->alerta(-128001, -35001, 'FIN_BOLSA', '2026-11-19 12:00:00');
        DB::table('alertas')->where('id', -128001)->update(['resuelta' => true]);
        $this->assertNull($this->reportes->resumen()['tiempo_promedio_respuesta']);
        $this->assertNull($this->reportes->alertasPorCategoria()[1]['tiempo_promedio_resolucion']);
        $this->atencion(-128001, '2026-11-19 12:00:00');
        $this->assertNull($this->reportes->resumen()['tiempo_promedio_respuesta']);
        $this->assertNull($this->reportes->alertasPorCategoria()[1]['tiempo_promedio_resolucion']);
        $this->atencion(-128001, '2026-11-19 12:00:02.5', true);
        $this->assertSame(2.5, $this->reportes->resumen()['tiempo_promedio_respuesta']);
        $this->assertSame(2.5, $this->reportes->alertasPorCategoria()[1]['tiempo_promedio_resolucion']);

        $this->alerta(-128002, -35002, 'FIN_BOLSA', '2026-11-19 12:01:00');
        $this->atencion(-128002, '2026-11-19 12:01:00', true);
        $this->assertSame(0.0, $this->reportes->resumen(['paciente_id' => -35002])['tiempo_promedio_respuesta']);
        $this->assertSame(0.0, $this->reportes->alertasPorCategoria(['paciente_id' => -35002])[1]['tiempo_promedio_resolucion']);
        $this->assertSame(1.25, $this->reportes->resumen()['tiempo_promedio_respuesta']);
        $this->assertSame(1.25, $this->reportes->alertasPorCategoria()[1]['tiempo_promedio_resolucion']);
    }

    public function test_date_limits_are_inclusive_and_use_the_application_timezone(): void
    {
        $this->seedDemo();
        config(['app.timezone' => 'America/Mexico_City']);
        DB::table('sesiones')->where('id', -35001)->update(['inicio' => '2026-11-18 05:59:59.999999+00']);
        DB::table('sesiones')->where('id', -35002)->update(['inicio' => '2026-11-18 06:00:00+00']);
        DB::table('sesiones')->where('id', -35003)->update(['inicio' => '2026-11-19 05:59:59.999999+00']);
        DB::table('sesiones')->where('id', -35004)->update(['inicio' => '2026-11-19 06:00:00+00']);
        $this->assertSame([-35003, -35002], $this->reportes->sesionesHistoricas($this->dia())->pluck('s.id')->all());
        $this->assertSame(2, $this->reportes->resumen($this->dia())['total_sesiones']);
        $this->assertSame(3, $this->reportes->resumen(['hasta' => '2026-11-18'])['total_sesiones']);
        $this->assertSame(9, $this->reportes->resumen(['desde' => '2026-11-18'])['total_sesiones']);
        $this->assertSame(10, $this->reportes->resumen()['total_sesiones']);
    }

    public function test_report_queries_are_read_only_and_optional_null_filters_are_accepted(): void
    {
        $this->escenario();
        $antes = $this->datos();
        $sinFiltros = $this->reportes->resumen();
        $this->assertSame($sinFiltros, $this->reportes->resumen([
            'desde' => null, 'hasta' => null, 'paciente_id' => null, 'sala' => null, 'enfermera_id' => null,
        ]));
        $this->reportes->alertasPorCategoria();
        $this->reportes->sesionesHistoricas()->get();
        $this->reportes->auditoriaSesion(-35001)->get();
        $this->assertSame($antes, $this->datos());
    }

    #[DataProvider('invalidos')]
    public function test_invalid_filters_are_rejected_before_querying(array $filtros, string $campo): void
    {
        try {
            $this->reportes->resumen($filtros);
            $this->fail('El filtro inválido debe rechazarse.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($campo, $exception->errors());
        }
    }

    public static function invalidos(): array
    {
        return [
            [['desde' => '2026-02-30'], 'desde'],
            [['hasta' => 'ayer'], 'hasta'],
            [['desde' => '2026-11-19', 'hasta' => '2026-11-18'], 'hasta'],
            [['paciente_id' => '1 OR 1=1'], 'paciente_id'],
            [['enfermera_id' => '1000000000000000000'], 'enfermera_id'],
            [['sala' => ['Sala A']], 'sala'],
        ];
    }

    public function test_index_migration_up_down_preserves_data_constraints_and_previous_indexes(): void
    {
        $this->seedDemo(false);
        $before = $this->firmas();
        $datos = $this->datos();
        $this->migrar();
        $this->assertCount(4, DB::select("SELECT indexname FROM pg_indexes WHERE schemaname = 'public' AND indexname LIKE 'idx_reportes_%'"));
        $this->assertSame(0, Artisan::call('migrate:rollback', ['--step' => 1, '--force' => true]));
        $this->assertEquals($before, $this->firmas());
        $this->assertSame($datos, $this->datos());
        $this->migrar();
        $this->assertCount(4, DB::select("SELECT indexname FROM pg_indexes WHERE schemaname = 'public' AND indexname LIKE 'idx_reportes_%'"));
    }

    public function test_adopted_tables_keep_their_rollback_guard_after_the_new_migration(): void
    {
        $sql = file_get_contents(base_path('../database/init.sql'));
        DB::unprepared(preg_replace('/^\s*(BEGIN|COMMIT);\s*$/m', '', $sql));
        $this->seedDemo();
        $this->assertSame(8, DB::table('dinostar_schema_adoptions')->count());
        $datos = $this->datos();
        $this->assertSame(10, $this->reportes->resumen()['total_sesiones']);
        $this->assertSame(0, Artisan::call('migrate:rollback', ['--step' => 1, '--force' => true]));
        $this->assertSame(8, DB::table('dinostar_schema_adoptions')->count());
        $this->assertSame($datos, $this->datos());
        $this->migrar();
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Rollback bloqueado');
        try {
            Artisan::call('migrate:reset', ['--force' => true]);
        } finally {
            foreach (DomainSchema::TABLES as $tabla) {
                $this->assertTrue(DB::getSchemaBuilder()->hasTable($tabla));
            }
            $this->assertSame(8, DB::table('dinostar_schema_adoptions')->count());
            $this->assertSame(10, DB::table('sesiones')->count());
        }
    }

    private function seedDemo(bool $indices = true): void
    {
        $this->migrar($indices);
        $this->assertSame(0, Artisan::call('db:seed', ['--force' => true, '--no-interaction' => true]));
    }

    private function migrar(bool $indices = true): void
    {
        $opciones = ['--force' => true, '--no-interaction' => true];
        if (! $indices) {
            $opciones['--path'] = array_map(
                fn (string $ruta): string => 'database/migrations/'.basename($ruta),
                glob(database_path('migrations/2026_09_*.php')),
            );
            $opciones['--path'][] = 'database/migrations/2026_10_10_000357_add_tipo_solucion_to_sesiones_table.php';
        }
        $this->assertSame(0, Artisan::call('migrate', $opciones));
    }

    private function escenario(): void
    {
        $this->seedDemo();
        User::factory()->create(['id' => -12801]);
        DB::table('usuarios')->where('id', -35001)->update(['activo' => false]);
        DB::table('pacientes')->where('id', -35002)->update(['sala' => 'Sala B']);
        DB::table('pacientes')->where('id', -35005)->update(['activo' => false]);
        DB::table('sesiones')->where('id', -35001)->update([
            'inicio' => '2026-11-18 00:00:00', 'fin' => '2026-11-18 01:00:00',
            'estado' => 'FINALIZADA', 'tipo_solucion' => 'Salina 0.9%',
        ]);
        DB::table('sesiones')->where('id', -35002)->update([
            'inicio' => '2026-11-18 23:59:59.999999', 'estado' => 'PAUSADA', 'enfermera_id' => -12801,
        ]);
        DB::table('sesiones')->where('id', -35003)->update(['inicio' => '2026-11-17 23:59:59.999999']);
        DB::table('sesiones')->where('id', -35004)->update(['inicio' => '2026-11-19 00:00:00', 'estado' => 'ERROR']);
        DB::table('sesiones')->where('id', -35005)->update([
            'inicio' => '2026-11-18 12:00:00', 'fin' => '2026-11-18 12:30:00', 'estado' => 'FINALIZADA',
        ]);
        $this->alerta(-128001, -35001, 'GOTEO_LENTO', '2026-11-18 10:00:00');
        $this->atencion(-128001, '2026-11-18 10:01:00');
        $this->atencion(-128001, '2026-11-18 10:03:00', true);
        $this->alerta(-128002, -35001, 'GOTEO_LENTO', '2026-11-18 23:59:59.999999');
        $this->alerta(-128003, -35002, 'FIN_BOLSA', '2026-11-17 23:55:00');
        $this->atencion(-128003, '2026-11-18 00:05:00', true);
        $this->alerta(-128004, -35003, 'GOTEO_RAPIDO', '2026-11-18 12:00:00');
        $this->atencion(-128004, '2026-11-19 00:00:00', true);
        $this->alerta(-128005, -35003, 'DESCONECTADO', '2026-11-18 13:00:00');
        $this->alerta(-128006, -35005, 'FIN_BOLSA', '2026-11-19 00:00:00');
        $this->alerta(-128007, -35002, 'GOTEO_RAPIDO', '2026-11-18 00:00:00');
        $this->atencion(-128007, '2026-11-18 00:00:30', true);
    }

    private function alerta(int $id, int $sesionId, string $tipo, string $fecha): void
    {
        DB::table('alertas')->insert([
            'id' => $id, 'sesion_id' => $sesionId, 'tipo' => $tipo, 'mensaje' => 'Alerta de prueba',
            'created_at' => $fecha, 'updated_at' => $fecha,
        ]);
    }

    private function atencion(int $alertaId, string $fecha, bool $resolvio = false): void
    {
        DB::table('atenciones_alerta')->insert([
            'alerta_id' => $alertaId, 'enfermera_id' => -35001, 'resolvio' => $resolvio,
            'created_at' => $fecha, 'updated_at' => $fecha,
        ]);
    }

    private function dia(): array
    {
        return ['desde' => '2026-11-18', 'hasta' => '2026-11-18'];
    }

    private function categoriasVacias(): array
    {
        return array_map(fn (string $tipo): array => [
            'tipo' => $tipo, 'cantidad' => 0, 'porcentaje' => 0.0, 'tiempo_promedio_resolucion' => null,
        ], ['GOTEO_LENTO', 'FIN_BOLSA', 'GOTEO_RAPIDO']);
    }

    private function firmas(): array
    {
        $firmas = [];
        foreach (DomainSchema::TABLES as $tabla) {
            $firmas[$tabla] = DomainSchema::signature('public', $tabla);
        }

        return $firmas;
    }

    private function datos(): array
    {
        $datos = [];
        foreach (DomainSchema::TABLES as $tabla) {
            $datos[$tabla] = DB::table($tabla)->orderBy('id')->get()->toJson();
        }

        return $datos;
    }
}
