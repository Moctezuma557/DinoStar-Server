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
        if (getenv('DINOSTAR_MIGRATION_TEST') !== '1') {
            $this->markTestSkipped('Requires isolated PostgreSQL on port 55432.');
        }
        $connection = [
            'driver' => 'pgsql', 'host' => '127.0.0.1', 'port' => '55432',
            'database' => 'postgres', 'username' => 'postgres', 'password' => 'issue22-test-only',
            'charset' => 'utf8', 'prefix' => '', 'search_path' => 'public', 'sslmode' => 'prefer',
        ];
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
