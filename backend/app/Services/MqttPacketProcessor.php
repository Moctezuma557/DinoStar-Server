<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use JsonException;
use stdClass;
use Throwable;

class MqttPacketProcessor
{
    public function __construct(private DataPacketService $service) {}

    /** Process the JSON list published by Braquio, isolating failures per packet. */
    public function procesar(string $topic, string $message): void
    {
        try {
            $packets = json_decode($message, false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
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
                $this->service->procesar((array) $packet);
            } catch (ValidationException $exception) {
                Log::warning('[MqttListen] DataPacket rechazado por validación.', $context + [
                    'campos' => array_keys($exception->errors()),
                ]);
            } catch (Throwable $exception) {
                Log::error('[MqttListen] No se pudo procesar el DataPacket.', $context + [
                    'exception' => $exception,
                ]);
            }
        }
    }
}
