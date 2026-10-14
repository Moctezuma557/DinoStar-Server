<?php

namespace App\Services;

class MqttPacketProcessor
{
    public function __construct(private DataPacketService $service) {}

    /** Compatibility entry point; mqtt:listen calls DataPacketService directly. */
    public function procesar(string $topic, string $message): void
    {
        $this->service->procesarMensaje($topic, $message);
    }
}
