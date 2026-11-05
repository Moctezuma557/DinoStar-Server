<?php

namespace Tests\Feature;

use App\Models\Alerta;
use App\Models\AtencionAlerta;
use App\Models\Paciente;
use App\Models\Sesion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AlertaApiTest extends TestCase
{
    use RefreshDatabase;

    private User $enfermera;

    protected function setUp(): void
    {
        parent::setUp();

        $this->enfermera = User::factory()->create(['rol' => 'ENFERMERA', 'activo' => true]);
    }

    private function payload(array $extra = []): array
    {
        return array_merge(['resuelta' => true, 'enfermera_id' => $this->enfermera->id], $extra);
    }

    public function test_lista_alertas_pendientes_con_paciente_y_cama(): void
    {
        $paciente = Paciente::factory()->create(['numero_cama' => 'A-12']);
        $sesion = Sesion::factory()->create(['paciente_id' => $paciente->id]);
        Alerta::factory()->create(['sesion_id' => $sesion->id]);
        Alerta::factory()->resuelta()->create(['sesion_id' => $sesion->id]);

        $this->getJson('/api/alertas?resuelta=false')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.resuelta', false)
            ->assertJsonPath('data.0.paciente.id', $paciente->id)
            ->assertJsonPath('data.0.cama', 'A-12');
    }

    public function test_filtra_por_sesion_id(): void
    {
        $sesionA = Sesion::factory()->create();
        $sesionB = Sesion::factory()->create();
        $alertaA = Alerta::factory()->create(['sesion_id' => $sesionA->id]);
        Alerta::factory()->create(['sesion_id' => $sesionB->id]);

        $this->getJson("/api/alertas?resuelta=false&sesion_id={$sesionA->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $alertaA->id);
    }

    public function test_acepta_sesion_id_vacio(): void
    {
        Alerta::factory()->create();

        $this->getJson('/api/alertas?resuelta=false&sesion_id=')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_alerta_desaparece_del_listado_al_resolverla(): void
    {
        $alerta = Alerta::factory()->create();

        $this->getJson('/api/alertas?resuelta=false')->assertJsonCount(1, 'data');

        $this->patchJson("/api/alertas/{$alerta->id}", $this->payload())->assertOk();

        $this->getJson('/api/alertas?resuelta=false')->assertJsonCount(0, 'data');
    }

    public function test_resolver_crea_exactamente_una_atencion_resolutiva(): void
    {
        $alerta = Alerta::factory()->create();

        $this->patchJson("/api/alertas/{$alerta->id}", $this->payload(['notas' => 'Se cambió la bolsa.']))
            ->assertOk()
            ->assertJsonPath('data.resuelta', true)
            ->assertJsonCount(1, 'data.atenciones');

        $this->assertDatabaseHas('alertas', ['id' => $alerta->id, 'resuelta' => true]);
        $this->assertDatabaseHas('atenciones_alerta', [
            'alerta_id' => $alerta->id,
            'enfermera_id' => $this->enfermera->id,
            'resolvio' => true,
            'notas' => 'Se cambió la bolsa.',
        ]);
        $this->assertSame(1, AtencionAlerta::where('alerta_id', $alerta->id)->where('resolvio', true)->count());
    }

    public function test_notas_son_opcionales(): void
    {
        $alerta = Alerta::factory()->create();

        $this->patchJson("/api/alertas/{$alerta->id}", $this->payload())->assertOk();

        $this->assertDatabaseHas('atenciones_alerta', ['alerta_id' => $alerta->id, 'notas' => null]);
    }

    public function test_resolver_dos_veces_responde_409_y_no_duplica(): void
    {
        $alerta = Alerta::factory()->create();

        $this->patchJson("/api/alertas/{$alerta->id}", $this->payload())->assertOk();
        $this->patchJson("/api/alertas/{$alerta->id}", $this->payload())->assertStatus(409);

        $this->assertSame(1, AtencionAlerta::where('alerta_id', $alerta->id)->count());
    }

    public function test_indice_unico_responde_409_y_revierte_la_transaccion(): void
    {
        // Estado inconsistente: alerta pendiente pero con una atención resolutiva previa.
        $alerta = Alerta::factory()->create();
        AtencionAlerta::create([
            'alerta_id' => $alerta->id,
            'enfermera_id' => $this->enfermera->id,
            'resolvio' => true,
        ]);

        $this->patchJson("/api/alertas/{$alerta->id}", $this->payload())->assertStatus(409);

        $this->assertDatabaseHas('alertas', ['id' => $alerta->id, 'resuelta' => false]);
        $this->assertSame(1, AtencionAlerta::where('alerta_id', $alerta->id)->count());
    }

    public function test_alerta_inexistente_responde_404(): void
    {
        $this->patchJson('/api/alertas/999999', $this->payload())->assertNotFound();
    }

    public function test_valida_enfermera_y_resuelta(): void
    {
        $alerta = Alerta::factory()->create();
        $admin = User::factory()->admin()->create();

        $this->patchJson("/api/alertas/{$alerta->id}", $this->payload(['enfermera_id' => 999999]))
            ->assertUnprocessable()->assertJsonValidationErrors('enfermera_id');

        $this->patchJson("/api/alertas/{$alerta->id}", $this->payload(['enfermera_id' => $admin->id]))
            ->assertUnprocessable()->assertJsonValidationErrors('enfermera_id');

        $this->patchJson("/api/alertas/{$alerta->id}", $this->payload(['resuelta' => false]))
            ->assertUnprocessable()->assertJsonValidationErrors('resuelta');

        $this->assertDatabaseHas('alertas', ['id' => $alerta->id, 'resuelta' => false]);
        $this->assertSame(0, AtencionAlerta::count());
    }
}
