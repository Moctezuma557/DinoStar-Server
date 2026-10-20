<?php

namespace App\Services;

use App\Models\Paciente;
use App\Models\Sesion;
use App\Events\NuevaLectura;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class DataPacketService
{
    /** Process one decoded device packet; validation failures propagate to the caller. */
    public function procesar(array $data): void
    {
        $data = Validator::make($data, [
            'pacienteId' => ['required', 'string', 'max:20', 'regex:/\S/u'],
            'gotasPorMin' => ['required', 'numeric', 'between:0,200'],
            'tiempoRestante' => ['required', 'integer', 'between:0,2147483647'],
            'volRestante' => ['required', 'numeric', 'between:0,5000'],
            'modo' => ['required', Rule::in(['NORMAL_GOTEO', 'MICRO_GOTEO'])],
            'timestamp' => ['required', 'integer', 'between:0,253402300799999'],
        ])->validate();

        $result = DB::transaction(function () use ($data): ?array {
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
            DB::afterCommit(function () use ($data): void {
                Log::info('[DataPacketService] Lectura guardada para '.$data['pacienteId'].' - '.$data['gotasPorMin'].' gotas/min');

                // Disparar el evento de broadcast por Reverb de forma segura tras el commit
                broadcast(new NuevaLectura(
                    pacienteId: $data['pacienteId'],
                    gotasPorMin: $data['gotasPorMin'],
                    volRestante: $data['volRestante'],
                    tiempoRestante: $data['tiempoRestante'],
                    modo: $data['modo'],
                    timestamp: $data['timestamp']
                ));
            });
        }
    }
}