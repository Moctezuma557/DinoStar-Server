<?php

namespace App\Services;

use App\Models\Paciente;
use App\Models\Sesion;
use App\Models\User;
use App\Events\NuevaLectura;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use JsonException;
use stdClass;
use Throwable;

class DataPacketService
{
    /** Receive the simulator's JSON directly, isolating errors within each batch. */
    public function procesarMensaje(string $topic, string $message, ?array $simulation = null): void
    {
        try {
            $packets = json_decode($message, false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            Log::warning('[MqttListen] JSON inválido; mensaje descartado.', ['topic' => $topic]);

            return;
        }
        if (! is_array($packets)) {
            Log::warning('[MqttListen] Se esperaba una lista de DataPackets.', ['topic' => $topic]);

            return;
        }
        Log::info('[MqttListen] Lote recibido.', ['topic' => $topic, 'paquetes' => count($packets)]);
        foreach ($packets as $index => $packet) {
            $context = ['topic' => $topic, 'indice' => $index];
            if (! $packet instanceof stdClass) {
                Log::warning('[MqttListen] DataPacket inválido; se esperaba un objeto.', $context);

                continue;
            }
            try {
                if ($simulation === null) {
                    $this->procesar((array) $packet);
                } else {
                    $this->procesar((array) $packet, $simulation);
                }
            } catch (ValidationException $exception) {
                Log::warning('[MqttListen] DataPacket rechazado por validación.', $context + [
                    'campos' => array_keys($exception->errors()),
                ]);
            } catch (Throwable $exception) {
                Log::error('[MqttListen] No se pudo procesar el DataPacket.', $context + ['exception' => $exception]);
            }
        }
    }

    /** Validate session metadata absent from the simulator's packet. */
    public function validarSimulacion(array $simulation): array
    {
        if (! app()->environment(['local', 'testing'])) {
            throw ValidationException::withMessages(['simulador' => 'La activación por simulador solo se permite en local/testing.']);
        }

        return Validator::make($simulation, [
            'enfermera_id' => ['required', 'integer', Rule::exists(User::class, 'id')->where('rol', 'ENFERMERA')->where('activo', true)],
            'vol_total' => ['required', 'numeric', 'gt:0', 'max:5000'],
        ])->validate();
    }

    /** Process one decoded device packet; validation failures propagate to the caller. */
    public function procesar(array $data, ?array $simulation = null): void
    {
        $data = Validator::make($data, [
            'pacienteId' => ['required', 'string', 'max:20', 'regex:/\S/u'],
            'gotasPorMin' => ['required', 'numeric', 'between:0,200'],
            'tiempoRestante' => ['required', 'integer', 'between:0,2147483647'],
            'volRestante' => ['required', 'numeric', 'between:0,5000'],
            'modo' => ['required', Rule::in(['NORMAL_GOTEO', 'MICRO_GOTEO'])],
            'timestamp' => ['required', 'integer', 'between:0,253402300799999'],
        ])->validate();

        if ($simulation !== null) {
            $simulation = $this->validarSimulacion($simulation);
        }

        $result = DB::transaction(function () use ($data, $simulation): ?array {
            $patients = Paciente::query()->where('numero_cama', $data['pacienteId'])
                ->where('activo', true)->lockForUpdate()->get();
            
            if ($patients->count() > 1) {
                Log::warning('[DataPacketService] Cama ambigua; no se guardaron datos.', ['pacienteId' => $data['pacienteId']]);

                return null;
            }

            $session = $patients->isEmpty() ? null : Sesion::query()
                ->where('paciente_id', $patients->first()->id)->where('estado', 'ACTIVA')->lockForUpdate()->first();
            
            if ($session === null) {
                Log::warning('[DataPacketService] No hay sesión activa para: '.$data['pacienteId']);

                return null;
            }

            // MODIFICACIÓN: Si el modo del paquete no coincide con la sesión, 
            // en lugar de rechazarlo, actualizamos la sesión de forma flexible para aceptarlo.
            if ($session->modo_goteo !== $data['modo']) {
                Log::info('[DataPacketService] Ajustando modo de sesión activo al recibido por el dispositivo.', [
                    'sesion_id' => $session->id,
                    'modo_anterior' => $session->modo_goteo,
                    'modo_nuevo' => $data['modo']
                ]);
                
                $session->update(['modo_goteo' => $data['modo']]);
            }

            $reading = $session->lecturas()->create([
                'gotas_por_min' => $data['gotasPorMin'],
                'vol_restante' => $data['volRestante'],
                'tiempo_restante_min' => $data['tiempoRestante'],
                'timestamp_dispositivo' => CarbonImmutable::createFromTimestampMs($data['timestamp'], 'UTC'),
            ]);

            $alerts = [];
            if ($data['volRestante'] < 50) {
                $alerts['FIN_BOLSA'] = 'Volumen restante crítico: '.$data['volRestante'].' mL';
            }
            if ($data['gotasPorMin'] < 20) {
                $alerts['GOTEO_LENTO'] = 'Goteo lento detectado: '.$data['gotasPorMin'].' gotas/min';
            }
            if ($data['gotasPorMin'] > 60) {
                $alerts['GOTEO_RAPIDO'] = 'Goteo rápido detectado: '.$data['gotasPorMin'].' gotas/min';
            }
            foreach ($alerts as $type => $message) {
                $session->alertas()->create(['tipo' => $type, 'mensaje' => $message, 'resuelta' => false]);
            }

            return ['lectura_id' => $reading->id, 'sesion_id' => $session->id, 'alertas' => array_keys($alerts)];
        });

        if ($result !== null) {
            DB::afterCommit(function () use ($data, $result): void {
                Log::info('[DataPacketService] Lectura guardada para '.$data['pacienteId'].' - '.$data['gotasPorMin'].' gotas/min');

                // Disparar el evento de broadcast por Reverb de forma segura tras el commit
                broadcast(new NuevaLectura(
                    pacienteId: $data['pacienteId'],
                    gotasPorMin: $data['gotasPorMin'],
                    volRestante: $data['volRestante'],
                    tiempoRestante: $data['tiempoRestante'],
                    modo: $data['modo'],
                    timestamp: $data['timestamp'],
                    alertas: $result['alertas'],
                    lecturaId: $result['lectura_id'],
                    sesionId: $result['sesion_id']
                ));
            });
        }
    }

    /** The patient is already locked; create at most one running session per bed. */
    private function sesionSimulada(Paciente $patient, array $data, array $simulation): ?Sesion
    {
        $session = $patient->sesiones()->whereIn('estado', ['ACTIVA', 'PAUSADA'])->lockForUpdate()->first();
        if ($session?->estado === 'PAUSADA') {
            Log::warning('[DataPacketService] Sesión pausada; el simulador no la reactiva.', ['pacienteId' => $data['pacienteId']]);

            return null;
        }
        if ($session !== null && $session->modo_goteo === $data['modo']) {
            return $session;
        }
        if ($data['volRestante'] > $simulation['vol_total']) {
            throw ValidationException::withMessages(['vol_total' => 'El volumen inicial configurado es menor que el volumen recibido.']);
        }

        $startedAt = now();
        if ($session !== null) {
            $startedAt = $startedAt->max($session->inicio);
            $session->update(['estado' => 'FINALIZADA', 'fin' => $startedAt]);
        }
        $created = $patient->sesiones()->create([
            'enfermera_id' => $simulation['enfermera_id'], 'vol_total' => $simulation['vol_total'],
            'modo_goteo' => $data['modo'], 'estado' => 'ACTIVA', 'inicio' => $startedAt,
        ]);
        DB::afterCommit(fn () => Log::info('[DataPacketService] Sesión activada por el simulador.', [
            'pacienteId' => $data['pacienteId'], 'sesion_id' => $created->id, 'modo' => $data['modo'],
        ]));

        return $created;
    }
}
