<?php

namespace App\Console\Commands;

use App\Services\MqttPacketProcessor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use PhpMqtt\Client\ConnectionSettings;
use PhpMqtt\Client\MqttClient;
use Throwable;

class MqttListen extends Command
{
    protected $signature = 'mqtt:listen';

    protected $description = 'Recibe los lotes de Braquio y guarda lecturas y alertas con DataPacketService';

    public function handle(MqttPacketProcessor $processor): int
    {
        $server = config('mqtt.host');
        $port = config('mqtt.port');
        $topic = config('mqtt.topic');
        $mqtt = new MqttClient($server, $port, 'laravel_patient_listener_'.uniqid());

        $this->info("Conectando al broker MQTT en {$server}:{$port}...");

        try {
            $settings = (new ConnectionSettings)->setKeepAliveInterval(60)->setConnectTimeout(10);
            $mqtt->connect($settings, true);
            $mqtt->subscribe($topic, function (string $topic, string $message) use ($processor): void {
                $processor->procesar($topic, $message);
            }, 0);

            if (function_exists('pcntl_signal')) {
                $this->trap([SIGTERM, SIGINT], fn () => $mqtt->interrupt());
            }

            $this->info("Escuchando {$topic}. Presiona Ctrl+C para terminar.");
            $mqtt->loop(true);
        } catch (Throwable $exception) {
            Log::error('[MqttListen] Falló la conexión o la escucha MQTT.', ['exception' => $exception]);
            $this->error('Error MQTT: '.$exception->getMessage());

            return self::FAILURE;
        } finally {
            if ($mqtt->isConnected()) {
                $mqtt->disconnect();
            }
        }

        return self::SUCCESS;
    }
}
