<?php

namespace Tests\Feature;

use App\Models\Alerta;
use App\Models\AtencionAlerta;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** K3: con AUTH_PROTEGER_RUTAS=true las rutas CRUD exigen token (401) y rol (403). */
class RutasProtegidasTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        // El flag se lee al registrar las rutas, asi que debe fijarse antes de arrancar la app.
        $_SERVER['AUTH_PROTEGER_RUTAS'] = 'true';
        parent::setUp();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        unset($_SERVER['AUTH_PROTEGER_RUTAS']);
    }

    /** @return array<string, array{0: string, 1: string, 2: list<string>}> metodo, ruta, roles permitidos */
    public static function rutas(): array
    {
        $admin = ['ADMIN'];
        $enfermera = ['ENFERMERA'];
        $ambos = ['ADMIN', 'ENFERMERA'];

        return [
            'GET usuarios' => ['GET', '/api/usuarios', $admin],
            'POST usuarios' => ['POST', '/api/usuarios', $admin],
            'PATCH usuarios' => ['PATCH', '/api/usuarios/1', $admin],
            'DELETE usuarios' => ['DELETE', '/api/usuarios/1', $admin],
            'POST enfermeras' => ['POST', '/api/enfermeras', $admin],
            'GET dispositivos logs' => ['GET', '/api/dispositivos/logs', $admin],
            'PATCH alertas' => ['PATCH', '/api/alertas/1', $enfermera],
            'GET alertas' => ['GET', '/api/alertas', $ambos],
            'GET enfermeras' => ['GET', '/api/enfermeras', $ambos],
            'GET enfermera' => ['GET', '/api/enfermeras/1', $ambos],
            'GET pacientes' => ['GET', '/api/pacientes', $ambos],
            'POST pacientes' => ['POST', '/api/pacientes', $ambos],
            'GET paciente' => ['GET', '/api/pacientes/1', $ambos],
            'PATCH paciente' => ['PATCH', '/api/pacientes/1', $ambos],
            'DELETE paciente' => ['DELETE', '/api/pacientes/1', $ambos],
            'POST sesiones' => ['POST', '/api/sesiones', $ambos],
            'PATCH sesion' => ['PATCH', '/api/sesiones/1', $ambos],
            'POST finalizar sesion' => ['POST', '/api/sesiones/1/finalizar', $ambos],
            'GET lecturas' => ['GET', '/api/sesiones/1/lecturas', $ambos],
        ];
    }

    private function conToken(string $rol): array
    {
        $usuario = $rol === 'ADMIN' ? User::factory()->admin()->create() : User::factory()->create();
        $token = $this->postJson('/api/login', ['email' => $usuario->email, 'password' => 'password'])
            ->assertOk()->json('data.token');
        $this->app['auth']->forgetGuards();

        return ['Authorization' => 'Bearer '.$token];
    }

    #[DataProvider('rutas')]
    public function test_sin_token_responde_401(string $metodo, string $ruta, array $permitidos): void
    {
        $this->json($metodo, $ruta)->assertUnauthorized()->assertJsonPath('code', 'no_autenticado');
    }

    #[DataProvider('rutas')]
    public function test_con_token_invalido_responde_401(string $metodo, string $ruta, array $permitidos): void
    {
        $this->json($metodo, $ruta, [], ['Authorization' => 'Bearer 1|falso'])->assertUnauthorized();
    }

    #[DataProvider('rutas')]
    public function test_con_rol_incorrecto_responde_403(string $metodo, string $ruta, array $permitidos): void
    {
        foreach (array_diff(['ADMIN', 'ENFERMERA'], $permitidos) as $rolIncorrecto) {
            $this->json($metodo, $ruta, [], $this->conToken($rolIncorrecto))
                ->assertForbidden()->assertJsonPath('code', 'rol_no_autorizado');
        }
        $this->addToAssertionCount(1);
    }

    #[DataProvider('rutas')]
    public function test_con_rol_permitido_la_ruta_responde_sin_401_ni_403(string $metodo, string $ruta, array $permitidos): void
    {
        foreach ($permitidos as $rol) {
            $estado = $this->json($metodo, $ruta, [], $this->conToken($rol))->getStatusCode();
            $this->assertNotContains($estado, [401, 403], "{$rol} deberia poder usar {$metodo} {$ruta}");
        }
    }

    public function test_el_listado_real_responde_200_con_token_valido(): void
    {
        User::factory()->create();

        $this->getJson('/api/usuarios', $this->conToken('ADMIN'))->assertOk()->assertJsonStructure(['data']);
        $this->getJson('/api/pacientes', $this->conToken('ENFERMERA'))->assertOk();
    }

    public function test_el_token_de_un_usuario_dado_de_baja_deja_de_servir_en_rutas_crud(): void
    {
        $encabezados = $this->conToken('ENFERMERA');
        User::query()->update(['activo' => false]);

        $this->app['auth']->forgetGuards();
        $this->getJson('/api/pacientes', $encabezados)->assertUnauthorized()->assertJsonPath('code', 'usuario_inactivo');
    }

    public function test_un_admin_degradado_pierde_acceso_a_rutas_de_admin_de_inmediato(): void
    {
        $admin = User::factory()->admin()->create();
        $otroAdmin = User::factory()->admin()->create();
        $token = $this->postJson('/api/login', ['email' => $admin->email, 'password' => 'password'])->json('data.token');
        $encabezados = ['Authorization' => 'Bearer '.$token];

        $this->app['auth']->forgetGuards();
        $this->getJson('/api/usuarios', $encabezados)->assertOk();

        $this->app['auth']->forgetGuards();
        $this->patchJson("/api/usuarios/{$admin->id}", ['rol' => 'ENFERMERA', 'turno' => 'MATUTINO'], $encabezados)->assertOk();

        // El cambio de rol revoca sus tokens, por lo que ya no hay sesion que reutilizar.
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/usuarios', $encabezados)->assertUnauthorized();
        $this->assertTrue($otroAdmin->fresh()->activo);
    }

    public function test_resolver_alerta_usa_la_enfermera_del_token_y_no_exige_enfermera_id(): void
    {
        $enfermera = User::factory()->create();
        $otra = User::factory()->create();
        $alerta = Alerta::factory()->create();
        $token = $this->postJson('/api/login', ['email' => $enfermera->email, 'password' => 'password'])->json('data.token');
        $this->app['auth']->forgetGuards();

        // enfermera_id del body (otra persona) se ignora: la autora es quien tiene la sesion.
        $this->patchJson("/api/alertas/{$alerta->id}", ['resuelta' => true, 'enfermera_id' => $otra->id], [
            'Authorization' => 'Bearer '.$token,
        ])->assertOk();

        $this->assertSame($enfermera->id, AtencionAlerta::where('alerta_id', $alerta->id)->value('enfermera_id'));
    }

    public function test_resolver_alerta_sin_enfermera_id_en_el_body_funciona_con_sesion(): void
    {
        $alerta = Alerta::factory()->create();

        $this->patchJson("/api/alertas/{$alerta->id}", ['resuelta' => true], $this->conToken('ENFERMERA'))
            ->assertOk()->assertJsonPath('data.resuelta', true);
    }
}
