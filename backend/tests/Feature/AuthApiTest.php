<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** K3: login, logout y me. Funcionan igual con AUTH_PROTEGER_RUTAS activo o no. */
class AuthApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-09 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function login(User $user, array $extra = [], string $password = 'password')
    {
        return $this->postJson('/api/login', ['email' => $user->email, 'password' => $password] + $extra);
    }

    private function bearer(string $token): array
    {
        return ['Authorization' => 'Bearer '.$token];
    }

    /** Fuerza a Sanctum a releer el token en la siguiente peticion del mismo test. */
    private function nuevaPeticion(): void
    {
        $this->app['auth']->forgetGuards();
    }

    public function test_login_valido_devuelve_token_usable_y_datos_del_usuario(): void
    {
        $enfermera = User::factory()->create(['nombre' => 'Ana Pérez']);

        $respuesta = $this->login($enfermera)->assertOk()
            ->assertJsonPath('data.token_type', 'Bearer')
            ->assertJsonPath('data.recordar', false)
            ->assertJsonPath('data.usuario.id', $enfermera->id)
            ->assertJsonPath('data.usuario.email', $enfermera->email)
            ->assertJsonPath('data.usuario.rol', 'ENFERMERA')
            ->assertJsonPath('data.usuario.turno', 'MATUTINO')
            ->assertJsonPath('data.usuario.activo', true)
            ->assertJsonMissingPath('data.usuario.password')
            ->assertJsonMissingPath('data.usuario.remember_token');

        $this->nuevaPeticion();
        $this->getJson('/api/me', $this->bearer($respuesta->json('data.token')))
            ->assertOk()->assertJsonPath('data.id', $enfermera->id);
    }

    public function test_el_token_se_guarda_como_hash_no_en_texto_plano(): void
    {
        $token = $this->login(User::factory()->create())->json('data.token');

        $this->assertDatabaseCount('personal_access_tokens', 1);
        $this->assertDatabaseMissing('personal_access_tokens', ['token' => $token]);
    }

    public function test_el_correo_no_distingue_mayusculas_ni_espacios(): void
    {
        $enfermera = User::factory()->create(['email' => 'ana@dinostar.test']);

        $this->postJson('/api/login', ['email' => '  ANA@Dinostar.TEST ', 'password' => 'password'])
            ->assertOk()->assertJsonPath('data.usuario.id', $enfermera->id);
    }

    public function test_contrasena_incorrecta_responde_401(): void
    {
        $this->login(User::factory()->create(), [], 'incorrecta')
            ->assertUnauthorized()
            ->assertExactJson(['message' => 'Credenciales inválidas.', 'code' => 'credenciales_invalidas']);

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_correo_inexistente_responde_igual_que_contrasena_incorrecta(): void
    {
        $this->postJson('/api/login', ['email' => 'nadie@dinostar.test', 'password' => 'password'])
            ->assertUnauthorized()
            ->assertExactJson(['message' => 'Credenciales inválidas.', 'code' => 'credenciales_invalidas']);
    }

    public function test_usuario_inactivo_no_puede_iniciar_sesion(): void
    {
        $inactivo = User::factory()->create(['activo' => false]);

        $this->login($inactivo)->assertUnauthorized()->assertJsonPath('code', 'usuario_inactivo');
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_usuario_inactivo_con_contrasena_incorrecta_no_revela_su_estado(): void
    {
        $this->login(User::factory()->create(['activo' => false]), [], 'incorrecta')
            ->assertUnauthorized()->assertJsonPath('code', 'credenciales_invalidas');
    }

    public function test_admin_inactivo_tampoco_puede_iniciar_sesion(): void
    {
        $this->login(User::factory()->admin()->create(['activo' => false]))
            ->assertUnauthorized()->assertJsonPath('code', 'usuario_inactivo');
    }

    public static function loginInvalidos(): array
    {
        return [
            'sin correo' => [['password' => 'x'], 'email'],
            'correo mal formado' => [['email' => 'no-es-correo', 'password' => 'x'], 'email'],
            'correo arreglo' => [['email' => ['a@b.c'], 'password' => 'x'], 'email'],
            'sin contrasena' => [['email' => 'a@dinostar.test'], 'password'],
            'contrasena vacia' => [['email' => 'a@dinostar.test', 'password' => ''], 'password'],
            'recordar no booleano' => [['email' => 'a@dinostar.test', 'password' => 'x', 'recordar' => 'quizas'], 'recordar'],
        ];
    }

    #[DataProvider('loginInvalidos')]
    public function test_datos_invalidos_responden_422(array $payload, string $campo): void
    {
        $this->postJson('/api/login', $payload)->assertUnprocessable()->assertJsonValidationErrors($campo);
    }

    public function test_enfermera_sin_recordar_recibe_token_de_un_turno(): void
    {
        $respuesta = $this->login(User::factory()->create())->assertOk()
            ->assertJsonPath('data.recordar', false);

        $this->assertSame(now()->addMinutes(480)->toISOString(), $respuesta->json('data.expires_at'));
        $this->assertSame('auth:sesion', PersonalAccessToken::first()->name);
    }

    public function test_enfermera_con_recordar_recibe_token_de_mayor_duracion(): void
    {
        $normal = $this->login(User::factory()->create())->json('data.expires_at');
        $recordado = $this->login(User::factory()->create(), ['recordar' => true])->assertOk()
            ->assertJsonPath('data.recordar', true);

        $this->assertSame(now()->addMinutes(43200)->toISOString(), $recordado->json('data.expires_at'));
        $this->assertTrue(Carbon::parse($recordado->json('data.expires_at'))->gt(Carbon::parse($normal)));
        $this->assertSame(1, PersonalAccessToken::where('name', 'auth:recordar')->count());
    }

    public function test_admin_siempre_recibe_sesion_corta_aunque_envie_recordar(): void
    {
        $admin = User::factory()->admin()->create();

        $sinRecordar = $this->login($admin)->assertOk();
        $conRecordar = $this->login($admin, ['recordar' => true])->assertOk()
            ->assertJsonPath('data.recordar', false)
            ->assertJsonPath('data.usuario.rol', 'ADMIN');

        $this->assertSame(now()->addMinutes(60)->toISOString(), $sinRecordar->json('data.expires_at'));
        $this->assertSame($sinRecordar->json('data.expires_at'), $conRecordar->json('data.expires_at'));
        $this->assertSame(0, PersonalAccessToken::where('name', 'auth:recordar')->count());
    }

    public function test_recordar_acepta_valores_booleanos_equivalentes(): void
    {
        foreach ([true, 1, '1'] as $valor) {
            $this->login(User::factory()->create(), ['recordar' => $valor])
                ->assertOk()->assertJsonPath('data.recordar', true);
        }
        foreach ([false, 0, '0', null] as $valor) {
            $this->login(User::factory()->create(), ['recordar' => $valor])
                ->assertOk()->assertJsonPath('data.recordar', false);
        }
    }

    public function test_token_vencido_responde_401(): void
    {
        $token = $this->login(User::factory()->create())->json('data.token');

        Carbon::setTestNow(now()->addMinutes(481));
        $this->nuevaPeticion();

        $this->getJson('/api/me', $this->bearer($token))->assertUnauthorized();
    }

    public function test_me_devuelve_usuario_y_rol(): void
    {
        $admin = User::factory()->admin()->create(['nombre' => 'Admin Uno']);
        $token = $this->login($admin)->json('data.token');

        $this->nuevaPeticion();
        $this->getJson('/api/me', $this->bearer($token))->assertOk()
            ->assertJsonPath('data.id', $admin->id)
            ->assertJsonPath('data.nombre', 'Admin Uno')
            ->assertJsonPath('data.rol', 'ADMIN')
            ->assertJsonPath('data.turno', null)
            ->assertJsonMissingPath('data.password');
    }

    public function test_me_sin_token_o_con_token_falso_responde_401_json(): void
    {
        $this->getJson('/api/me')->assertUnauthorized()->assertJsonPath('code', 'no_autenticado');
        $this->getJson('/api/me', $this->bearer('1|token-inventado'))->assertUnauthorized();
        // Sin Accept: application/json tambien debe ser JSON, nunca redireccion ni 500.
        $this->get('/api/me')->assertUnauthorized()->assertJsonPath('code', 'no_autenticado');
    }

    public function test_logout_revoca_el_token_actual(): void
    {
        $token = $this->login(User::factory()->create())->json('data.token');

        $this->nuevaPeticion();
        $this->postJson('/api/logout', [], $this->bearer($token))
            ->assertOk()->assertJsonPath('message', 'Sesión cerrada correctamente.');
        $this->assertDatabaseCount('personal_access_tokens', 0);

        $this->nuevaPeticion();
        $this->getJson('/api/me', $this->bearer($token))->assertUnauthorized();
    }

    public function test_logout_solo_cierra_la_sesion_usada_y_no_las_demas_del_usuario(): void
    {
        $enfermera = User::factory()->create();
        $tokenA = $this->login($enfermera)->json('data.token');
        $tokenB = $this->login($enfermera)->json('data.token');

        $this->nuevaPeticion();
        $this->postJson('/api/logout', [], $this->bearer($tokenA))->assertOk();

        $this->nuevaPeticion();
        $this->getJson('/api/me', $this->bearer($tokenA))->assertUnauthorized();
        $this->nuevaPeticion();
        $this->getJson('/api/me', $this->bearer($tokenB))->assertOk();
    }

    public function test_logout_sin_token_responde_401(): void
    {
        $this->postJson('/api/logout')->assertUnauthorized();
    }

    public function test_un_usuario_dado_de_baja_despues_de_entrar_pierde_el_acceso(): void
    {
        $enfermera = User::factory()->create();
        $token = $this->login($enfermera)->json('data.token');

        // Baja directa en BD: el middleware "activo" debe cubrirla aunque nadie borre tokens.
        DB::table('usuarios')->where('id', $enfermera->id)->update(['activo' => false]);

        $this->nuevaPeticion();
        $this->getJson('/api/me', $this->bearer($token))
            ->assertUnauthorized()->assertJsonPath('code', 'usuario_inactivo');
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_dar_de_baja_por_la_api_de_usuarios_revoca_sus_tokens(): void
    {
        $enfermera = User::factory()->create();
        $this->login($enfermera);
        $this->login($enfermera);
        $this->assertSame(2, $enfermera->tokens()->count());

        $this->deleteJson("/api/usuarios/{$enfermera->id}")->assertNoContent();

        $this->assertSame(0, $enfermera->tokens()->count());
    }

    public function test_cambiar_contrasena_o_desactivar_por_patch_revoca_tokens_pero_editar_el_nombre_no(): void
    {
        $enfermera = User::factory()->create();
        $this->login($enfermera);

        $this->patchJson("/api/usuarios/{$enfermera->id}", ['nombre' => 'Otro nombre'])->assertOk();
        $this->assertSame(1, $enfermera->tokens()->count());

        $this->patchJson("/api/usuarios/{$enfermera->id}", ['password' => 'Nueva-Clave-123'])->assertOk();
        $this->assertSame(0, $enfermera->tokens()->count());

        $this->login($enfermera, [], 'Nueva-Clave-123')->assertOk();
        $this->patchJson("/api/usuarios/{$enfermera->id}", ['activo' => false])->assertOk();
        $this->assertSame(0, $enfermera->tokens()->count());
    }

    public function test_demasiados_intentos_de_login_responden_429(): void
    {
        $enfermera = User::factory()->create();

        for ($i = 0; $i < 10; $i++) {
            $this->login($enfermera, [], 'incorrecta')->assertUnauthorized();
        }

        $this->login($enfermera, [], 'incorrecta')->assertStatus(429);
        // Con la contrasena correcta tambien: el freno es por correo e IP.
        $this->login($enfermera)->assertStatus(429);
    }

    public function test_con_la_proteccion_apagada_las_rutas_crud_siguen_abiertas(): void
    {
        $this->getJson('/api/usuarios')->assertOk();
        $this->getJson('/api/pacientes')->assertOk();
    }
}
