<?php

namespace App\Services;

use App\Models\LogDispositivo;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use JsonException;
use stdClass;
use Throwable;

class MqttPacketProcessor
{
    public function __construct(private DataPacketService $service) {}

    /** Procesa el mensaje MQTT recibido y registra incidencias en la base de datos. */
    public function procesar(string $topic, string $message): void
    {
        try {
            $packets = json_decode($message, false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            Log::warning('[MqttListen] JSON inválido; mensaje descartado.', ['topic' => $topic]);

            // Registrar JSON inválido en la BD
            LogDispositivo::create([
                'tipo' => 'error',
                'categoria' => 'mqtt',
                'mensaje' => "JSON inválido recibido en el tópico {$topic}.",
            ]);

            return;
        }

        if (!is_array($packets)) {
            Log::warning('[MqttListen] Se esperaba una lista de DataPackets.', ['topic' => $topic]);

            // Registrar paquete que no es lista en la BD
            LogDispositivo::create([
                'tipo' => 'error',
                'categoria' => 'mqtt',
                'mensaje' => "El mensaje en {$topic} no es una lista JSON válida.",
            ]);

            return;
        }

        Log::info('[MqttListen] Lote recibido.', ['topic' => $topic, 'paquetes' => count($packets)]);

        foreach ($packets as $index => $packet) {
            $context = ['topic' => $topic, 'indice' => $index];
            
            if (!$packet instanceof stdClass) {
                Log::warning('[MqttListen] DataPacket inválido; se esperaba un objeto.', $context);

                // Registrar paquete inválido (no es objeto) en la BD
                LogDispositivo::create([
                    'tipo' => 'error',
                    'categoria' => 'packet',
                    'mensaje' => "DataPacket inválido en el índice {$index} (se esperaba un objeto). Tópico: {$topic}.",
                ]);

                continue;
            }

            $packetArray = (array) $packet;
            $numeroCama = $packetArray['pacienteId'] ?? null;

            try {
                $this->service->procesar($packetArray);
            } catch (ValidationException $exception) {
                Log::warning('[MqttListen] DataPacket rechazado por validación.', $context + [
                    'campos' => array_keys($exception->errors()),
                ]);

                // Registrar validación fallida en la BD
                LogDispositivo::create([
                    'tipo' => 'error',
                    'categoria' => 'packet',
                    'mensaje' => 'Validación fallida: ' . json_encode($exception->errors()),
                    'numero_cama' => $numeroCama,
                ]);
            } catch (Throwable $exception) {
                Log::error('[MqttListen] No se pudo procesar el DataPacket.', $context + ['exception' => $exception]);

                LogDispositivo::create([
                    'tipo' => 'error',
                    'categoria' => 'packet',
                    'mensaje' => 'Error inesperado al procesar paquete: ' . $exception->getMessage(),
                    'numero_cama' => $numeroCama,
                ]);
            }
        }
    }
}