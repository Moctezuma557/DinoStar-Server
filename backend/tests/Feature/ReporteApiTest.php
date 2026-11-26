<?php

namespace Tests\Feature;

use App\Models\Alerta;
use App\Models\Paciente;
use App\Models\Sesion;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\DemoSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View;
use Illuminate\Testing\TestResponse;
use Illuminate\View\View as BladeView;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ReporteApiTest extends TestCase
{
    private ?string $database = null;

    private mixed $proteccionAnterior;

    private array $documento = [];

    protected function setUp(): void
    {
        $this->proteccionAnterior = $_SERVER['AUTH_PROTEGER_RUTAS'] ?? null;
        $_SERVER['AUTH_PROTEGER_RUTAS'] = 'false';
        parent::setUp();
        // Con DINOSTAR_MIGRATION_TEST=1 se usa la instancia dedicada (puerto 55432). Sin la
        // variable se usa el servidor PostgreSQL de la conexión por defecto (el servicio
        // `database` de docker compose): solo se le pide crear y borrar una base temporal,
        // así que `dinostar_rex` y sus datos nunca se tocan.
        if (getenv('DINOSTAR_MIGRATION_TEST') === '1') {
            $connection = [
                'driver' => 'pgsql', 'host' => '127.0.0.1', 'port' => '55432',
                'database' => 'postgres', 'username' => 'postgres', 'password' => 'issue22-test-only',
                'charset' => 'utf8', 'prefix' => '', 'search_path' => 'public', 'sslmode' => 'prefer',
            ];
        } else {
            $connection = config('database.connections.pgsql');
            if (($connection['driver'] ?? null) !== 'pgsql') {
                $this->markTestSkipped('Requires a PostgreSQL default connection.');
            }
        }
        config(['database.connections.reportes_pdf_admin' => $connection]);
        $this->database = 'dinostar135_'.bin2hex(random_bytes(8));
        DB::connection('reportes_pdf_admin')->statement('CREATE DATABASE "'.$this->database.'"');
        config([
            'database.default' => 'pgsql',
            'database.connections.pgsql' => array_replace($connection, ['database' => $this->database]),
            'app.timezone' => 'UTC',
        ]);
        DB::purge('pgsql');
        $this->travelTo(CarbonImmutable::parse('2026-11-21 12:00:00', 'UTC'));
        $this->assertSame(0, Artisan::call('migrate', ['--force' => true, '--no-interaction' => true]));
        View::composer('reportes.pdf', function (BladeView $view): void {
            $this->documento = $view->getData();
        });
    }

    protected function tearDown(): void
    {
        try {
            if ($this->database !== null) {
                DB::purge('pgsql');
                DB::connection('reportes_pdf_admin')->statement('DROP DATABASE "'.$this->database.'"');
                DB::purge('reportes_pdf_admin');
            }
        } finally {
            if ($this->proteccionAnterior === null) {
                unset($_SERVER['AUTH_PROTEGER_RUTAS']);
            } else {
                $_SERVER['AUTH_PROTEGER_RUTAS'] = $this->proteccionAnterior;
            }
            parent::tearDown();
        }
    }

    public function test_descarga_un_pdf_real_con_los_datos_del_seeder(): void
    {
        $this->seed(DemoSeeder::class);

        $this->assertPdf($this->exportar());

        $this->assertSame(10, $this->documento['resumen']['total_sesiones']);
        $this->assertSame(0, $this->documento['resumen']['sesiones_completadas']['cantidad']);
        $this->assertNull($this->documento['resumen']['tiempo_promedio_respuesta']);
        $this->assertCount(10, $this->documento['sesiones']);
        $this->assertSame(['GOTEO_LENTO', 'FIN_BOLSA', 'GOTEO_RAPIDO'], array_column($this->documento['categorias'], 'tipo'));
        foreach (['Total de sesiones', 'Sesiones completadas', 'Sesiones con alertas', 'Tiempo promedio de respuesta',
            'Alertas por categoría', 'Sesiones auditadas (10)', 'Enfermera Demo', 'Paciente 01', 'Paciente 10', 'En curso'] as $texto) {
            $this->assertStringContainsString($texto, $this->html());
        }
    }

    public function test_el_pdf_conserva_los_promedios_resolutivos_y_la_fecha_de_la_alerta_de_b1(): void
    {
        $this->escenario();

        $this->assertPdf($this->exportar(['fecha_inicio' => '2026-11-20', 'fecha_fin' => '2026-11-20']));

        $this->assertSame([
            'total_sesiones' => 2,
            'sesiones_completadas' => ['cantidad' => 1, 'porcentaje' => 50.0],
            'sesiones_con_alertas' => 3,
            'tiempo_promedio_respuesta' => 43245.0,
        ], $this->documento['resumen']);
        $this->assertSame([
            ['tipo' => 'GOTEO_LENTO', 'cantidad' => 1, 'porcentaje' => 25.0, 'tiempo_promedio_resolucion' => 90.0],
            ['tipo' => 'FIN_BOLSA', 'cantidad' => 1, 'porcentaje' => 25.0, 'tiempo_promedio_resolucion' => 86400.0],
            ['tipo' => 'GOTEO_RAPIDO', 'cantidad' => 1, 'porcentaje' => 25.0, 'tiempo_promedio_resolucion' => null],
        ], $this->documento['categorias']);
        $this->assertSame([-13502, -13501], $this->documento['sesiones']->pluck('id')->all());
        $html = $this->html();
        foreach (['43,245.00 s', '86,400.00 s', '90.00 s', '25.00%', '—', 'Paciente PDF Álvarez', '20.00 min', 'Solución salina 0.9%'] as $texto) {
            $this->assertStringContainsString($texto, $html);
        }
        $this->assertStringNotContainsString('Paciente PDF C', $html);
    }

    // ---------------------------------------------------------------------
    // K1: GET /api/reportes/resumen y GET /api/reportes/alertas-categoria
    // ---------------------------------------------------------------------

    public function test_resumen_coincide_con_un_conteo_sql_directo_para_el_mismo_rango(): void
    {
        $this->escenario();

        $directo = DB::selectOne(<<<'SQL'
            SELECT
                (SELECT COUNT(*) FROM sesiones
                 WHERE inicio >= '2026-11-20T00:00:00Z' AND inicio < '2026-11-21T00:00:00Z') AS total,
                (SELECT COUNT(*) FROM sesiones WHERE estado = 'FINALIZADA'
                 AND inicio >= '2026-11-20T00:00:00Z' AND inicio < '2026-11-21T00:00:00Z') AS completadas,
                (SELECT COUNT(DISTINCT sesion_id) FROM alertas
                 WHERE created_at >= '2026-11-20T00:00:00Z' AND created_at < '2026-11-21T00:00:00Z') AS con_alertas,
                (SELECT AVG(EXTRACT(EPOCH FROM (t.created_at - a.created_at)))
                 FROM atenciones_alerta t JOIN alertas a ON a.id = t.alerta_id
                 WHERE t.resolvio = TRUE AND a.created_at >= '2026-11-20T00:00:00Z'
                 AND a.created_at < '2026-11-21T00:00:00Z') AS respuesta
            SQL);

        $datos = $this->reporte('resumen', ['fecha_inicio' => '2026-11-20', 'fecha_fin' => '2026-11-20'])
            ->assertOk()->json('data');

        $this->assertSame((int) $directo->total, $datos['total_sesiones']);
        $this->assertSame((int) $directo->completadas, $datos['sesiones_completadas']['cantidad']);
        $this->assertSame(round($directo->completadas * 100 / $directo->total, 2), $datos['sesiones_completadas']['porcentaje']);
        $this->assertSame((int) $directo->con_alertas, $datos['sesiones_con_alertas']);
        $this->assertSame(round((float) $directo->respuesta, 2), $datos['tiempo_promedio_respuesta']);
    }

    public function test_resumen_devuelve_exactamente_los_campos_del_contrato_de_b1(): void
    {
        $this->escenario();

        $respuesta = $this->reporte('resumen', ['fecha_inicio' => '2026-11-20', 'fecha_fin' => '2026-11-20'])
            ->assertOk()->assertHeader('Content-Type', 'application/json');

        // Sin KPIs extra: nada de sesiones interrumpidas, meta 90 %, % vs mes anterior ni ciclo promedio.
        $this->assertSame([
            'data' => [
                'total_sesiones' => 2,
                'sesiones_completadas' => ['cantidad' => 1, 'porcentaje' => 50.0],
                'sesiones_con_alertas' => 3,
                'tiempo_promedio_respuesta' => 43245.0,
            ],
        ], $respuesta->json());
        // El porcentaje conserva el decimal (50.0 y no 50) para que V1 reciba siempre un float.
        $this->assertStringContainsString('"porcentaje":50.0', $respuesta->getContent());
    }

    public function test_alertas_categoria_calcula_el_porcentaje_sobre_todas_las_alertas_incluida_desconectado(): void
    {
        $this->escenario();

        $respuesta = $this->reporte('alertas-categoria', ['fecha_inicio' => '2026-11-20', 'fecha_fin' => '2026-11-20'])
            ->assertOk();

        // 4 alertas en el rango (una es DESCONECTADO): los tres tipos suman 75 %, no se fuerza el 100 %.
        $this->assertSame(['data' => [
            ['tipo' => 'GOTEO_LENTO', 'cantidad' => 1, 'porcentaje' => 25.0, 'tiempo_promedio_resolucion' => 90.0],
            ['tipo' => 'FIN_BOLSA', 'cantidad' => 1, 'porcentaje' => 25.0, 'tiempo_promedio_resolucion' => 86400.0],
            ['tipo' => 'GOTEO_RAPIDO', 'cantidad' => 1, 'porcentaje' => 25.0, 'tiempo_promedio_resolucion' => null],
        ]], $respuesta->json());
        $this->assertSame(75.0, array_sum(array_column($respuesta->json('data'), 'porcentaje')));
        $this->assertSame(4, (int) DB::selectOne(
            "SELECT COUNT(*) AS total FROM alertas WHERE created_at >= '2026-11-20T00:00:00Z' AND created_at < '2026-11-21T00:00:00Z'",
        )->total);
    }

    public function test_alertas_categoria_suma_100_cuando_no_hay_alertas_desconectado(): void
    {
        $this->escenario();
        DB::table('alertas')->where('tipo', 'DESCONECTADO')->delete();

        $categorias = $this->reporte('alertas-categoria', ['fecha_inicio' => '2026-11-20', 'fecha_fin' => '2026-11-20'])
            ->assertOk()->json('data');

        $this->assertSame([1, 1, 1], array_column($categorias, 'cantidad'));
        $this->assertEqualsWithDelta(100.0, array_sum(array_column($categorias, 'porcentaje')), 0.02);
    }

    public function test_un_rango_sin_datos_devuelve_ceros_y_null_sin_error(): void
    {
        $this->escenario();
        $vacio = ['fecha_inicio' => '2020-01-01', 'fecha_fin' => '2020-01-31'];

        $resumen = $this->reporte('resumen', $vacio)->assertOk();
        $this->assertSame(['data' => [
            'total_sesiones' => 0,
            'sesiones_completadas' => ['cantidad' => 0, 'porcentaje' => 0.0],
            'sesiones_con_alertas' => 0,
            'tiempo_promedio_respuesta' => null,
        ]], $resumen->json());
        $this->assertStringContainsString('"tiempo_promedio_respuesta":null', $resumen->getContent());

        $categorias = $this->reporte('alertas-categoria', $vacio)->assertOk();
        $this->assertSame(['data' => [
            ['tipo' => 'GOTEO_LENTO', 'cantidad' => 0, 'porcentaje' => 0.0, 'tiempo_promedio_resolucion' => null],
            ['tipo' => 'FIN_BOLSA', 'cantidad' => 0, 'porcentaje' => 0.0, 'tiempo_promedio_resolucion' => null],
            ['tipo' => 'GOTEO_RAPIDO', 'cantidad' => 0, 'porcentaje' => 0.0, 'tiempo_promedio_resolucion' => null],
        ]], $categorias->json());
        $this->assertStringContainsString('"porcentaje":0.0', $categorias->getContent());
    }

    public function test_una_base_vacia_tampoco_devuelve_error(): void
    {
        $this->reporte('resumen')->assertOk()->assertJsonPath('data.total_sesiones', 0)
            ->assertJsonPath('data.tiempo_promedio_respuesta', null);
        $this->reporte('alertas-categoria')->assertOk()->assertJsonCount(3, 'data');
    }

    public static function filtrosK1(): array
    {
        $dia = ['fecha_inicio' => '2026-11-20', 'fecha_fin' => '2026-11-20'];

        return [
            'sin filtros' => [[], 3, 2, 3, 5, [1, 2, 1], 43245.0],
            'rango de un dia' => [$dia, 2, 1, 3, 4, [1, 1, 1], 43245.0],
            'paciente con id negativo' => [$dia + ['paciente_id' => -13501], 1, 1, 1, 1, [1, 0, 0], 90.0],
            'sala' => [['sala' => 'Sala PDF B'], 1, 1, 1, 2, [0, 1, 0], 86400.0],
            'enfermera' => [$dia + ['enfermera_id' => -13502], 1, 0, 1, 1, [0, 0, 1], null],
            'combinados sin coincidencias' => [['paciente_id' => -13501, 'sala' => 'Sala PDF B'], 0, 0, 0, 0, [0, 0, 0], null],
            'los cinco filtros' => [$dia + ['paciente_id' => -13501, 'sala' => 'Sala PDF A', 'enfermera_id' => -13501], 1, 1, 1, 1, [1, 0, 0], 90.0],
            'vacios sin filtro' => [['fecha_inicio' => '', 'fecha_fin' => '', 'paciente_id' => '', 'sala' => '', 'enfermera_id' => ''], 3, 2, 3, 5, [1, 2, 1], 43245.0],
        ];
    }

    #[DataProvider('filtrosK1')]
    public function test_los_cinco_filtros_se_aplican_a_resumen_y_a_alertas_categoria(
        array $filtros,
        int $total,
        int $completadas,
        int $conAlertas,
        int $alertasDelRango,
        array $cantidades,
        ?float $respuesta,
    ): void {
        $this->escenario();

        $resumen = $this->reporte('resumen', $filtros)->assertOk()->json('data');
        $this->assertSame($total, $resumen['total_sesiones']);
        $this->assertSame($completadas, $resumen['sesiones_completadas']['cantidad']);
        $this->assertSame($conAlertas, $resumen['sesiones_con_alertas']);
        $this->assertSame($respuesta, $resumen['tiempo_promedio_respuesta']);

        $categorias = $this->reporte('alertas-categoria', $filtros)->assertOk()->json('data');
        $this->assertSame(['GOTEO_LENTO', 'FIN_BOLSA', 'GOTEO_RAPIDO'], array_column($categorias, 'tipo'));
        $this->assertSame($cantidades, array_column($categorias, 'cantidad'));
        foreach ($categorias as $categoria) {
            $esperado = $alertasDelRango === 0 ? 0.0 : round($categoria['cantidad'] * 100 / $alertasDelRango, 2);
            $this->assertSame($esperado, $categoria['porcentaje'], $categoria['tipo']);
        }
    }

    #[DataProvider('filtrosInvalidos')]
    public function test_los_filtros_invalidos_de_los_reportes_json_devuelven_422(array $filtros, string $campo): void
    {
        $this->reporte('resumen', $filtros)->assertUnprocessable()->assertJsonValidationErrors($campo);
        $this->reporte('alertas-categoria', $filtros)->assertUnprocessable()->assertJsonValidationErrors($campo);
    }

    public function test_los_reportes_json_son_de_solo_lectura(): void
    {
        $this->escenario();
        $tablas = ['usuarios', 'pacientes', 'sesiones', 'alertas', 'atenciones_alerta', 'logs_auditoria'];
        $antes = [];
        foreach ($tablas as $tabla) {
            $antes[$tabla] = DB::table($tabla)->orderBy('id')->get()->toJson();
        }

        $this->reporte('resumen')->assertOk();
        $this->reporte('alertas-categoria')->assertOk();
        $this->postJson('/api/reportes/resumen')->assertStatus(405);
        $this->postJson('/api/reportes/alertas-categoria')->assertStatus(405);

        foreach ($tablas as $tabla) {
            $this->assertSame($antes[$tabla], DB::table($tabla)->orderBy('id')->get()->toJson(), $tabla);
        }
    }

    // ---------------------------------------------------------------------
    // K2: GET /api/reportes/sesiones y GET /api/reportes/sesiones/{id}/alertas
    // ---------------------------------------------------------------------

    public function test_sesiones_con_per_page_10_devuelve_10_filas_y_metadata_de_paginacion(): void
    {
        $this->escenario();
        $this->sesionesFinalizadas(12);

        $respuesta = $this->reporte('sesiones', ['per_page' => 10])->assertOk();

        $respuesta->assertJsonCount(10, 'data')
            ->assertJsonStructure([
                'data' => [['id', 'paciente', 'enfermera', 'ubicacion' => ['sala', 'numero_cama'], 'inicio', 'fin',
                    'duracion_segundos', 'tipo_solucion', 'vol_total', 'numero_alertas', 'estado']],
                'links' => ['first', 'last', 'prev', 'next'],
                'meta' => ['current_page', 'from', 'last_page', 'per_page', 'to', 'total'],
            ]);
        $this->assertSame(
            ['current_page' => 1, 'from' => 1, 'last_page' => 2, 'per_page' => 10, 'to' => 10, 'total' => 15],
            collect($respuesta->json('meta'))->only(['current_page', 'from', 'last_page', 'per_page', 'to', 'total'])->all(),
        );

        $segunda = $this->reporte('sesiones', ['per_page' => 10, 'page' => 2])->assertOk();
        $segunda->assertJsonCount(5, 'data')->assertJsonPath('meta.current_page', 2)->assertJsonPath('meta.from', 11);
        $this->assertCount(
            15,
            array_unique([...array_column($respuesta->json('data'), 'id'), ...array_column($segunda->json('data'), 'id')]),
            'Las páginas no deben repetir sesiones.',
        );
    }

    public function test_sesiones_sin_per_page_usa_10_filas_por_defecto(): void
    {
        $this->escenario();
        $this->sesionesFinalizadas(12);

        $this->reporte('sesiones')->assertOk()->assertJsonCount(10, 'data')
            ->assertJsonPath('meta.per_page', 10)->assertJsonPath('meta.total', 15);
    }

    public function test_sesiones_se_ordenan_por_inicio_descendente_y_incluyen_todos_los_estados(): void
    {
        $this->escenario();

        $filas = $this->reporte('sesiones')->assertOk()->json('data');

        $this->assertSame([-13502, -13501, -13503], array_column($filas, 'id'));
        $this->assertSame(['ACTIVA', 'FINALIZADA', 'FINALIZADA'], array_column($filas, 'estado'));
    }

    public function test_cada_fila_de_sesiones_trae_las_columnas_de_la_tabla_de_b1(): void
    {
        $this->escenario();

        $respuesta = $this->reporte('sesiones', ['fecha_inicio' => '2026-11-20', 'fecha_fin' => '2026-11-20'])->assertOk();

        $this->assertSame([
            'id' => -13502, 'paciente' => 'Paciente PDF B', 'enfermera' => 'Enfermera PDF Dos',
            'ubicacion' => ['sala' => 'Sala PDF A', 'numero_cama' => 'PDF--13502'],
            'inicio' => '2026-11-20T23:59:59.000000Z', 'fin' => null, 'duracion_segundos' => null,
            'tipo_solucion' => 'SALINA_0_9', 'vol_total' => 1000.0, 'numero_alertas' => 1, 'estado' => 'ACTIVA',
        ], $respuesta->json('data.0'));
        $this->assertSame([
            'id' => -13501, 'paciente' => 'Paciente PDF Álvarez', 'enfermera' => 'Enfermera PDF Uno',
            'ubicacion' => ['sala' => 'Sala PDF A', 'numero_cama' => 'PDF--13501'],
            'inicio' => '2026-11-20T00:00:00.000000Z', 'fin' => '2026-11-20T00:20:00.000000Z', 'duracion_segundos' => 1200.0,
            'tipo_solucion' => 'Solución salina 0.9%', 'vol_total' => 500.0, 'numero_alertas' => 2, 'estado' => 'FINALIZADA',
        ], $respuesta->json('data.1'));
        $this->assertSame(
            ['id', 'paciente', 'enfermera', 'ubicacion', 'inicio', 'fin', 'duracion_segundos', 'tipo_solucion', 'vol_total', 'numero_alertas', 'estado'],
            array_keys($respuesta->json('data.0')),
        );
        $this->assertStringContainsString('"duracion_segundos":1200.0', $respuesta->getContent());
        $this->assertStringContainsString('"duracion_segundos":null', $respuesta->getContent());
    }

    #[DataProvider('filtros')]
    public function test_los_filtros_reducen_las_sesiones_listadas(array $filtros, array $esperadas): void
    {
        $this->escenario();

        $respuesta = $this->reporte('sesiones', $filtros)->assertOk();

        $this->assertSame($esperadas, array_column($respuesta->json('data'), 'id'));
        $this->assertSame(count($esperadas), $respuesta->json('meta.total'));
    }

    public function test_los_filtros_se_combinan_con_la_paginacion_y_se_conservan_en_los_enlaces(): void
    {
        $this->escenario();

        $respuesta = $this->reporte('sesiones', [
            'fecha_inicio' => '2026-11-20', 'fecha_fin' => '2026-11-20', 'per_page' => 1,
        ])->assertOk();

        $respuesta->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', -13502)
            ->assertJsonPath('meta.total', 2)->assertJsonPath('meta.last_page', 2);
        $this->assertStringContainsString('fecha_inicio=2026-11-20', $respuesta->json('links.next'));
        $this->assertStringContainsString('per_page=1', $respuesta->json('links.next'));
    }

    public function test_sesiones_sin_coincidencias_devuelve_una_pagina_vacia_sin_error(): void
    {
        $this->escenario();

        $this->reporte('sesiones', ['fecha_inicio' => '2020-01-01', 'fecha_fin' => '2020-01-31'])
            ->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('meta.total', 0);
        $this->reporte('sesiones', ['page' => 99])->assertOk()->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.total', 3);
    }

    #[DataProvider('filtrosInvalidos')]
    public function test_los_filtros_invalidos_de_sesiones_devuelven_422(array $filtros, string $campo): void
    {
        $this->reporte('sesiones', $filtros)->assertUnprocessable()->assertJsonValidationErrors($campo);
    }

    public static function paginacionInvalida(): array
    {
        return [
            'per_page cero' => [['per_page' => 0], 'per_page'],
            'per_page sobre el maximo' => [['per_page' => 101], 'per_page'],
            'per_page no entero' => [['per_page' => 'diez'], 'per_page'],
            'page cero' => [['page' => 0], 'page'],
            'page negativa' => [['page' => -1], 'page'],
            'page fuera de rango' => [['page' => '99999999999999999999'], 'page'],
        ];
    }

    #[DataProvider('paginacionInvalida')]
    public function test_la_paginacion_invalida_devuelve_422(array $parametros, string $campo): void
    {
        $this->reporte('sesiones', $parametros)->assertUnprocessable()->assertJsonValidationErrors($campo);
    }

    public function test_auditoria_de_una_sesion_lista_cada_alerta_con_su_atencion(): void
    {
        $this->escenario();

        $respuesta = $this->getJson('/api/reportes/sesiones/-13501/alertas')->assertOk();
        $filas = $respuesta->json('data');

        $this->assertCount(3, $filas);
        $this->assertSame(['GOTEO_LENTO', 'GOTEO_LENTO', 'FIN_BOLSA'], array_column($filas, 'tipo'));
        $this->assertSame(['2026-11-20T00:00:10.000000Z', '2026-11-20T00:00:10.000000Z', '2026-11-21T00:00:00.000000Z'], array_column($filas, 'hora'));
        $this->assertSame(['Enfermera PDF Uno', 'Enfermera PDF Uno', null], array_column($filas, 'atendida_por'));
        $this->assertSame([false, true, null], array_column($filas, 'resolvio'));
        $this->assertSame(
            DB::table('alertas')->where('sesion_id', -13501)->orderBy('created_at')->pluck('mensaje')->all(),
            [$filas[0]['descripcion'], $filas[2]['descripcion']],
        );
        $this->assertSame($filas[0]['descripcion'], $filas[1]['descripcion']);
    }

    public function test_la_auditoria_expone_solo_las_columnas_de_b1(): void
    {
        $this->escenario();

        $filas = $this->getJson('/api/reportes/sesiones/-13501/alertas')->assertOk()->json('data');

        $this->assertSame(['id', 'tipo', 'hora', 'descripcion', 'atendida_por', 'resolvio', 'tiempo_respuesta'], array_keys($filas[1]));
    }

    public function test_el_tiempo_de_respuesta_solo_cuenta_la_atencion_que_resolvio(): void
    {
        $this->escenario();

        $filas = $this->getJson('/api/reportes/sesiones/-13501/alertas')->assertOk()->json('data');

        // Atención 00:00:40 (resolvio=false) -> null; atención 00:01:40 (resolvio=true) -> 100 s - 10 s = 90 s; sin atención -> null.
        $this->assertSame([null, 90.0, null], array_column($filas, 'tiempo_respuesta'));
        $this->assertNull($filas[2]['atendida_por']);
        $this->assertNull($filas[2]['resolvio']);

        $otra = $this->getJson('/api/reportes/sesiones/-13503/alertas')->assertOk();
        $this->assertSame(['FIN_BOLSA', 'DESCONECTADO'], array_column($otra->json('data'), 'tipo'));
        $this->assertSame([86400.0, null], array_column($otra->json('data'), 'tiempo_respuesta'));
        $this->assertStringContainsString('"tiempo_respuesta":86400.0', $otra->getContent());
        $this->assertStringContainsString('"tiempo_respuesta":null', $otra->getContent());
    }

    public function test_el_tiempo_de_respuesta_de_la_auditoria_coincide_con_el_calculo_sql_de_b1(): void
    {
        $this->escenario();

        $directo = DB::select(<<<'SQL'
            SELECT a.id, EXTRACT(EPOCH FROM (t.created_at - a.created_at))::double precision AS segundos
            FROM alertas a JOIN atenciones_alerta t ON t.alerta_id = a.id
            WHERE t.resolvio = TRUE AND a.sesion_id IN (-13501, -13503) ORDER BY a.id
            SQL);

        $api = [];
        foreach ([-13501, -13503] as $sesion) {
            foreach ($this->getJson("/api/reportes/sesiones/{$sesion}/alertas")->assertOk()->json('data') as $fila) {
                if ($fila['tiempo_respuesta'] !== null) {
                    $api[$fila['id']] = $fila['tiempo_respuesta'];
                }
            }
        }
        ksort($api);

        $this->assertSame(array_column($directo, 'segundos', 'id'), $api);
    }

    public function test_una_sesion_sin_alertas_devuelve_data_vacia(): void
    {
        $this->escenario();
        $limpia = Sesion::factory()->create(['id' => -13504, 'paciente_id' => -13503, 'enfermera_id' => -13501, 'estado' => 'FINALIZADA', 'fin' => now()]);

        $this->getJson("/api/reportes/sesiones/{$limpia->id}/alertas")->assertOk()->assertExactJson(['data' => []]);
        $this->assertSame(0, $this->reporte('sesiones', ['paciente_id' => -13503, 'per_page' => 100])
            ->json('data.0.numero_alertas'));
    }

    public function test_numero_alertas_de_la_tabla_coincide_con_las_alertas_auditadas(): void
    {
        $this->escenario();

        foreach ($this->reporte('sesiones', ['per_page' => 100])->assertOk()->json('data') as $sesion) {
            $auditadas = collect($this->getJson("/api/reportes/sesiones/{$sesion['id']}/alertas")->assertOk()->json('data'))
                ->pluck('id')->unique()->count();
            $this->assertSame($sesion['numero_alertas'], $auditadas, 'sesión '.$sesion['id']);
        }
    }

    public function test_la_auditoria_de_una_sesion_inexistente_o_con_id_invalido_responde_404(): void
    {
        $this->escenario();

        $this->getJson('/api/reportes/sesiones/999999/alertas')->assertNotFound()
            ->assertJsonPath('message', 'Sesión no encontrada.');
        $this->getJson('/api/reportes/sesiones/abc/alertas')->assertNotFound();
    }

    public function test_los_reportes_de_sesiones_son_de_solo_lectura(): void
    {
        $this->escenario();
        $tablas = ['usuarios', 'pacientes', 'sesiones', 'alertas', 'atenciones_alerta', 'logs_auditoria'];
        $antes = [];
        foreach ($tablas as $tabla) {
            $antes[$tabla] = DB::table($tabla)->orderBy('id')->get()->toJson();
        }

        $this->reporte('sesiones')->assertOk();
        $this->getJson('/api/reportes/sesiones/-13501/alertas')->assertOk();
        $this->postJson('/api/reportes/sesiones')->assertStatus(405);
        $this->deleteJson('/api/reportes/sesiones/-13501/alertas')->assertStatus(405);

        foreach ($tablas as $tabla) {
            $this->assertSame($antes[$tabla], DB::table($tabla)->orderBy('id')->get()->toJson(), $tabla);
        }
    }

    /** Crea sesiones FINALIZADAS (cada una con su paciente) que inician antes que las del escenario. */
    private function sesionesFinalizadas(int $cantidad): void
    {
        $enfermera = User::factory()->create();
        for ($i = 1; $i <= $cantidad; $i++) {
            Sesion::factory()->create([
                'enfermera_id' => $enfermera->id, 'estado' => 'FINALIZADA',
                'inicio' => now()->subDays(10)->subMinutes($i), 'fin' => now()->subDays(10),
            ]);
        }
    }

    private function reporte(string $ruta, array $filtros = []): TestResponse
    {
        return $this->getJson('/api/reportes/'.$ruta.'?'.http_build_query($filtros));
    }

    public static function filtros(): array
    {
        return [
            'fechas inclusivas' => [['fecha_inicio' => '2026-11-20', 'fecha_fin' => '2026-11-20'], [-13502, -13501]],
            'paciente con id negativo' => [['paciente_id' => -13501], [-13501]],
            'sala' => [['sala' => 'Sala PDF B'], [-13503]],
            'enfermera' => [['enfermera_id' => -13502], [-13502]],
            'combinados sin coincidencias' => [['paciente_id' => -13501, 'sala' => 'Sala PDF B'], []],
            'solo fecha inicial' => [['fecha_inicio' => '2026-11-20'], [-13502, -13501]],
            'solo fecha final' => [['fecha_fin' => '2026-11-19'], [-13503]],
            'vacios sin filtro' => [['fecha_inicio' => '', 'fecha_fin' => '', 'paciente_id' => '', 'sala' => '', 'enfermera_id' => ''], [-13502, -13501, -13503]],
        ];
    }

    #[DataProvider('filtros')]
    public function test_respeta_los_filtros_de_la_pantalla(array $filtros, array $esperadas): void
    {
        $this->escenario();

        $this->assertPdf($this->exportar($filtros));

        $this->assertSame($esperadas, $this->documento['sesiones']->pluck('id')->all());
        $this->assertSame(count($esperadas), $this->documento['resumen']['total_sesiones']);
        if ($esperadas === []) {
            $this->assertStringContainsString('No se encontraron sesiones con los filtros seleccionados.', $this->html());
        }
    }

    public static function filtrosInvalidos(): array
    {
        return [
            'fecha inicial invalida' => [['fecha_inicio' => '2026-02-30'], 'fecha_inicio'],
            'fecha final invalida' => [['fecha_fin' => 'ayer'], 'fecha_fin'],
            'rango invertido' => [['fecha_inicio' => '2026-11-21', 'fecha_fin' => '2026-11-20'], 'fecha_fin'],
            'paciente no entero' => [['paciente_id' => 'Elena'], 'paciente_id'],
            'paciente fuera del rango' => [['paciente_id' => '1000000000000000000'], 'paciente_id'],
            'enfermera no entera' => [['enfermera_id' => '1.5'], 'enfermera_id'],
            'sala muy larga' => [['sala' => str_repeat('s', 51)], 'sala'],
            'sala como array' => [['sala' => ['Sala A']], 'sala'],
        ];
    }

    public function test_los_cinco_filtros_se_aplican_a_kpis_categorias_y_sesiones(): void
    {
        $this->escenario();

        $this->assertPdf($this->exportar([
            'fecha_inicio' => '2026-11-20', 'fecha_fin' => '2026-11-20',
            'paciente_id' => -13501, 'sala' => 'Sala PDF A', 'enfermera_id' => -13501,
        ]));

        $this->assertSame([-13501], $this->documento['sesiones']->pluck('id')->all());
        $this->assertSame([
            'total_sesiones' => 1,
            'sesiones_completadas' => ['cantidad' => 1, 'porcentaje' => 100.0],
            'sesiones_con_alertas' => 1,
            'tiempo_promedio_respuesta' => 90.0,
        ], $this->documento['resumen']);
        $this->assertSame([1, 0, 0], array_column($this->documento['categorias'], 'cantidad'));
        $this->assertSame([90.0, null, null], array_column($this->documento['categorias'], 'tiempo_promedio_resolucion'));
    }

    #[DataProvider('filtrosInvalidos')]
    public function test_los_filtros_invalidos_devuelven_422_en_json(array $filtros, string $campo): void
    {
        $this->exportar($filtros)->assertUnprocessable()->assertJsonValidationErrors($campo);
        $this->assertSame([], $this->documento);
    }

    public function test_sin_datos_descarga_pdf_con_mensaje_y_promedios_nulos(): void
    {
        $this->assertPdf($this->exportar());

        $this->assertSame(0, $this->documento['resumen']['total_sesiones']);
        $this->assertSame(0, $this->documento['resumen']['sesiones_con_alertas']);
        $this->assertNull($this->documento['resumen']['tiempo_promedio_respuesta']);
        $this->assertSame([null, null, null], array_column($this->documento['categorias'], 'tiempo_promedio_resolucion'));
        $this->assertStringContainsString('No se encontraron sesiones con los filtros seleccionados.', $this->html());
    }

    public function test_un_rango_vacio_tambien_descarga_pdf(): void
    {
        $this->seed(DemoSeeder::class);

        $this->assertPdf($this->exportar(['fecha_inicio' => '2020-01-01', 'fecha_fin' => '2020-01-31']));

        $this->assertCount(0, $this->documento['sesiones']);
        $this->assertSame(0, $this->documento['resumen']['total_sesiones']);
        $this->assertStringContainsString('No se encontraron sesiones con los filtros seleccionados.', $this->html());
    }

    public function test_exporta_todas_las_sesiones_en_varias_paginas_sin_limitarse_a_la_paginacion_web(): void
    {
        $this->seed(DemoSeeder::class);
        $paciente = Paciente::factory()->create(['sala' => 'Sala multipagina', 'numero_cama' => 'PDF-MULTI']);
        $enfermera = User::factory()->create();
        Sesion::factory()->count(30)->create([
            'paciente_id' => $paciente->id, 'enfermera_id' => $enfermera->id,
            'estado' => 'FINALIZADA', 'fin' => now(),
        ]);

        $respuesta = $this->exportar(['page' => 2, 'per_page' => 1]);
        $this->assertPdf($respuesta);

        $this->assertCount(40, $this->documento['sesiones']);
        $this->assertSame(40, $this->documento['resumen']['total_sesiones']);
        $this->assertGreaterThan(1, preg_match_all('/\/Type\s*\/Page\b/', $respuesta->getContent()));
    }

    public function test_escapa_los_textos_y_no_exporta_credenciales(): void
    {
        $this->escenario();
        Paciente::whereKey(-13501)->update([
            'nombre' => '<img src="https://example.test/privado">', 'sala' => '<script>Sala PDF</script>',
        ]);
        User::whereKey(-13501)->update(['nombre' => '<b>Enfermera PDF</b>']);
        Sesion::whereKey(-13501)->update(['tipo_solucion' => '<i>Solucion PDF</i>']);

        $this->assertPdf($this->exportar(['paciente_id' => -13501, 'sala' => '<script>Sala PDF</script>']));

        $html = $this->html();
        $this->assertStringContainsString('&lt;img', $html);
        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('<b>Enfermera PDF</b>', $html);
        $this->assertStringNotContainsString('<i>Solucion PDF</i>', $html);
        $this->assertStringNotContainsString('password', $html);
        $this->assertStringNotContainsString('Bearer', $html);
    }

    public function test_generar_el_pdf_no_modifica_los_datos(): void
    {
        $this->escenario();
        $tablas = ['usuarios', 'pacientes', 'sesiones', 'alertas', 'atenciones_alerta', 'logs_auditoria'];
        $antes = [];
        foreach ($tablas as $tabla) {
            $antes[$tabla] = DB::table($tabla)->orderBy('id')->get()->toJson();
        }

        $this->assertPdf($this->exportar());

        foreach ($tablas as $tabla) {
            $this->assertSame($antes[$tabla], DB::table($tabla)->orderBy('id')->get()->toJson(), $tabla);
        }
    }

    private function exportar(array $filtros = []): TestResponse
    {
        return $this->get('/api/reportes/exportar?'.http_build_query($filtros), ['Accept' => 'application/pdf']);
    }

    private function assertPdf(TestResponse $respuesta): void
    {
        $respuesta->assertOk()->assertHeader('Content-Type', 'application/pdf')
            ->assertDownload('dinostar-reporte-20261121-120000.pdf')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('no-store', $respuesta->headers->get('Cache-Control'));
        $pdf = $respuesta->getContent();
        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertMatchesRegularExpression('/%%EOF\s*$/', $pdf);
        $this->assertStringContainsString('/Type /Catalog', $pdf);
        $this->assertStringContainsString('/Type /Pages', $pdf);
    }

    private function html(): string
    {
        return view('reportes.pdf', $this->documento)->render();
    }

    private function escenario(): void
    {
        $enfermera = User::factory()->create(['id' => -13501, 'nombre' => 'Enfermera PDF Uno']);
        $otra = User::factory()->create(['id' => -13502, 'nombre' => 'Enfermera PDF Dos']);
        foreach ([[-13501, 'Paciente PDF Álvarez', 'Sala PDF A'], [-13502, 'Paciente PDF B', 'Sala PDF A'], [-13503, 'Paciente PDF C', 'Sala PDF B']] as [$id, $nombre, $sala]) {
            Paciente::factory()->create(['id' => $id, 'nombre' => $nombre, 'sala' => $sala, 'numero_cama' => 'PDF-'.$id]);
        }
        Sesion::factory()->create([
            'id' => -13501, 'paciente_id' => -13501, 'enfermera_id' => $enfermera->id,
            'inicio' => '2026-11-20T00:00:00Z', 'fin' => '2026-11-20T00:20:00Z',
            'estado' => 'FINALIZADA', 'tipo_solucion' => 'Solución salina 0.9%', 'vol_total' => 500,
        ]);
        Sesion::factory()->create([
            'id' => -13502, 'paciente_id' => -13502, 'enfermera_id' => $otra->id,
            'inicio' => '2026-11-20T23:59:59Z', 'estado' => 'ACTIVA',
        ]);
        Sesion::factory()->create([
            'id' => -13503, 'paciente_id' => -13503, 'enfermera_id' => $enfermera->id,
            'inicio' => '2026-11-19T23:59:59Z', 'fin' => '2026-11-20T00:05:00Z', 'estado' => 'FINALIZADA',
        ]);
        $lenta = Alerta::factory()->create([
            'sesion_id' => -13501, 'tipo' => 'GOTEO_LENTO', 'resuelta' => true, 'created_at' => '2026-11-20T00:00:10Z',
        ]);
        $bolsa = Alerta::factory()->create([
            'sesion_id' => -13503, 'tipo' => 'FIN_BOLSA', 'resuelta' => true, 'created_at' => '2026-11-20T10:00:00Z',
        ]);
        Alerta::factory()->create(['sesion_id' => -13502, 'tipo' => 'GOTEO_RAPIDO', 'created_at' => '2026-11-20T23:59:59Z']);
        Alerta::factory()->create(['sesion_id' => -13503, 'tipo' => 'DESCONECTADO', 'created_at' => '2026-11-20T10:01:00Z']);
        Alerta::factory()->create(['sesion_id' => -13501, 'tipo' => 'FIN_BOLSA', 'created_at' => '2026-11-21T00:00:00Z']);
        foreach ([[$lenta->id, false, '2026-11-20T00:00:40Z'], [$lenta->id, true, '2026-11-20T00:01:40Z'], [$bolsa->id, true, '2026-11-21T10:00:00Z']] as [$id, $resolvio, $fecha]) {
            DB::table('atenciones_alerta')->insert(['alerta_id' => $id, 'enfermera_id' => $enfermera->id, 'resolvio' => $resolvio, 'created_at' => $fecha]);
        }
    }
}
