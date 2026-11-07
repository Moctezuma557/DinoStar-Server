<?php

namespace Tests\Feature;

use App\Models\Paciente;
use App\Models\Sesion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SesionTest extends TestCase
{
    use RefreshDatabase;

    private User $enfermera;

    private Paciente $paciente;

    protected function setUp(): void
    {
        parent::setUp();

        $this->enfermera = User::factory()->create([
            'rol' => 'ENFERMERA',
            'activo' => true,
        ]);

        $this->paciente = Paciente::factory()->create([
            'activo' => true,
        ]);
    }

    public function test_puede_crear_una_sesion_activa_para_un_paciente(): void
    {
        $payload = [
            'paciente_id' => $this->paciente->id,
            'enfermera_id' => $this->enfermera->id,
            'vol_total' => 1000,
            'modo_goteo' => 'NORMAL_GOTEO',
            'tipo_solucion' => 'SALINA_0_9',
        ];

        $response = $this->postJson('/api/sesiones', $payload);

        $response->assertStatus(201)
            ->assertJsonPath('data.estado', 'ACTIVA')
            ->assertJsonPath('data.paciente_id', $this->paciente->id);

        $this->assertDatabaseHas('sesiones', [
            'paciente_id' => $this->paciente->id,
            'estado' => 'ACTIVA',
        ]);
    }

    public function test_rechaza_crear_segunda_sesion_activa_o_pausada_para_mismo_paciente(): void
    {
        Sesion::factory()->create([
            'paciente_id' => $this->paciente->id,
            'estado' => 'ACTIVA',
        ]);

        $payload = [
            'paciente_id' => $this->paciente->id,
            'enfermera_id' => $this->enfermera->id,
            'vol_total' => 500,
            'modo_goteo' => 'NORMAL_GOTEO',
            'tipo_solucion' => 'GLUCOSA_5',
        ];

        $response = $this->postJson('/api/sesiones', $payload);

        $response->assertStatus(409);
    }

    public function test_puede_editar_solo_los_campos_permitidos_de_una_sesion(): void
    {
        $sesion = Sesion::factory()->create([
            'vol_total' => 1000,
            'modo_goteo' => 'NORMAL_GOTEO',
            'tipo_solucion' => 'SALINA_0_9',
            'enfermera_id' => $this->enfermera->id,
        ]);

        $nuevaEnfermera = User::factory()->create([
            'rol' => 'ENFERMERA',
            'activo' => true,
        ]);

        $payload = [
            'vol_total' => 2500,
            'modo_goteo' => 'MICRO_GOTEO',
            'tipo_solucion' => 'HARTMANN',
            'enfermera_id' => $nuevaEnfermera->id,
        ];

        $response = $this->patchJson("/api/sesiones/{$sesion->id}", $payload);

        $response->assertStatus(200)
            ->assertJsonPath('data.vol_total', '2500.00')
            ->assertJsonPath('data.modo_goteo', 'MICRO_GOTEO')
            ->assertJsonPath('data.tipo_solucion', 'HARTMANN')
            ->assertJsonPath('data.enfermera_id', $nuevaEnfermera->id);
    }

    public function test_finaliza_sesion_correctamente_y_rechaza_segunda_finalizacion(): void
    {
        $sesion = Sesion::factory()->create([
            'estado' => 'ACTIVA',
            'fin' => null,
        ]);

        $responsePrimera = $this->postJson("/api/sesiones/{$sesion->id}/finalizar");

        $responsePrimera->assertStatus(200)
            ->assertJsonPath('data.estado', 'FINALIZADA');

        $this->assertNotNull($sesion->fresh()->fin);

        $responseSegunda = $this->postJson("/api/sesiones/{$sesion->id}/finalizar");

        $responseSegunda->assertStatus(409)
            ->assertJson([
                'message' => 'La sesión ya se encuentra finalizada.',
            ]);
    }

    private function payloadValido(array $cambios = []): array
    {
        return array_replace([
            'paciente_id' => $this->paciente->id,
            'enfermera_id' => $this->enfermera->id,
            'vol_total' => 500,
            'modo_goteo' => 'NORMAL_GOTEO',
            'tipo_solucion' => 'SALINA_0_9',
        ], $cambios);
    }

    public function test_editar_ignora_campos_no_permitidos(): void
    {
        $sesion = Sesion::factory()->create(['estado' => 'ACTIVA']);
        $otroPaciente = Paciente::factory()->create();

        $response = $this->patchJson("/api/sesiones/{$sesion->id}", [
            'vol_total' => 750,
            'estado' => 'ERROR',
            'paciente_id' => $otroPaciente->id,
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.vol_total', '750.00')
            ->assertJsonPath('data.estado', 'ACTIVA');

        $this->assertNotEquals($otroPaciente->id, $sesion->fresh()->paciente_id);
    }

    public function test_rechaza_editar_una_sesion_finalizada(): void
    {
        $sesion = Sesion::factory()->create([
            'estado' => 'FINALIZADA',
            'fin' => now(),
            'vol_total' => 1000,
        ]);

        $this->patchJson("/api/sesiones/{$sesion->id}", ['vol_total' => 2000])
            ->assertStatus(409)
            ->assertJson(['message' => 'No se puede editar una sesión finalizada.']);

        $this->assertEquals(1000, $sesion->fresh()->vol_total);
    }

    public function test_devuelve_404_si_la_sesion_no_existe(): void
    {
        $this->patchJson('/api/sesiones/999999', ['vol_total' => 100])
            ->assertStatus(404)
            ->assertJson(['message' => 'Sesión no encontrada.']);

        $this->postJson('/api/sesiones/999999/finalizar')
            ->assertStatus(404)
            ->assertJson(['message' => 'Sesión no encontrada.']);
    }

    public function test_devuelve_404_si_el_id_de_la_sesion_no_es_numerico(): void
    {
        $this->postJson('/api/sesiones/abc/finalizar')->assertStatus(404);
    }

    public function test_rechaza_crear_sesion_para_paciente_inactivo(): void
    {
        $inactivo = Paciente::factory()->create(['activo' => false]);

        $this->postJson('/api/sesiones', $this->payloadValido(['paciente_id' => $inactivo->id]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('paciente_id');

        $this->assertDatabaseMissing('sesiones', ['paciente_id' => $inactivo->id]);
    }

    public function test_rechaza_crear_sesion_con_enfermera_admin_o_inactiva(): void
    {
        $admin = User::factory()->admin()->create();
        $inactiva = User::factory()->create(['rol' => 'ENFERMERA', 'activo' => false]);

        foreach ([$admin, $inactiva] as $usuario) {
            $this->postJson('/api/sesiones', $this->payloadValido(['enfermera_id' => $usuario->id]))
                ->assertStatus(422)
                ->assertJsonValidationErrors('enfermera_id');
        }
    }

    public function test_rechaza_crear_sesion_con_modo_de_goteo_invalido(): void
    {
        $this->postJson('/api/sesiones', $this->payloadValido(['modo_goteo' => 'XYZ']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('modo_goteo');
    }

    #[DataProvider('volumenesInvalidos')]
    public function test_rechaza_crear_sesion_con_vol_total_fuera_de_rango(mixed $volumen): void
    {
        $this->postJson('/api/sesiones', $this->payloadValido(['vol_total' => $volumen]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('vol_total');
    }

    public static function volumenesInvalidos(): array
    {
        return [
            'cero' => [0],
            'negativo' => [-10],
            'mayor al maximo' => [5001],
            'no numerico' => ['abc'],
        ];
    }

    public function test_acepta_el_volumen_maximo_permitido(): void
    {
        $this->postJson('/api/sesiones', $this->payloadValido(['vol_total' => 5000]))
            ->assertStatus(201);
    }

    public function test_rechaza_crear_sesion_sin_campos_obligatorios(): void
    {
        $this->postJson('/api/sesiones', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['paciente_id', 'enfermera_id', 'vol_total', 'modo_goteo', 'tipo_solucion']);
    }

    public function test_rechaza_editar_con_datos_invalidos(): void
    {
        $sesion = Sesion::factory()->create();

        $this->patchJson("/api/sesiones/{$sesion->id}", ['modo_goteo' => 'XYZ', 'vol_total' => 9999])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['modo_goteo', 'vol_total']);
    }
}
