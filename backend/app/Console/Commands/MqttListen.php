<?php

namespace App\Console\Commands;

use App\Services\DataPacketService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use PhpMqtt\Client\ConnectionSettings;
use PhpMqtt\Client\MqttClient;
use Throwable;

class MqttListen extends Command
{
    protected $signature = 'mqtt:listen
        {--simulador : Activa sesiones para las camas recibidas en local/testing}
        {--enfermera= : ID de la enfermera para las nuevas sesiones simuladas}
        {--volumen-inicial= : Volumen inicial en mL para las nuevas sesiones simuladas}';

    protected $description = 'Recibe los lotes de Braquio y guarda lecturas y alertas con DataPacketService';

    public function handle(DataPacketService $service): int
    {
        $simulation = null;
        if ($this->option('simulador')) {
            try {
                $simulation = $service->validarSimulacion([
                    'enfermera_id' => $this->option('enfermera'),
                    'vol_total' => $this->option('volumen-inicial'),
                ]);
            } catch (ValidationException $exception) {
                foreach ($exception->validator->errors()->all() as $error) {
                    $this->error($error);
                }

                return self::FAILURE;
            }
            $this->warn('Simulación: las camas registradas recibidas podrán iniciar sesiones; un cambio de modo iniciará otra sesión conservando el historial.');
        } elseif ($this->option('enfermera') !== null || $this->option('volumen-inicial') !== null) {
            $this->error('Usa --simulador junto con --enfermera y --volumen-inicial.');

            return self::FAILURE;
        }
        $server = config('mqtt.host');
        $port = config('mqtt.port');
        $topic = config('mqtt.topic');
        $mqtt = new MqttClient($server, $port, 'laravel_patient_listener_'.uniqid());

        $this->info("Conectando al broker MQTT en {$server}:{$port}...");

        try {
            $settings = (new ConnectionSettings)->setKeepAliveInterval(60)->setConnectTimeout(10);
            $mqtt->connect($settings, true);
            $mqtt->subscribe($topic, function (string $topic, string $message) use ($service, $simulation): void {
                $service->procesarMensaje($topic, $message, $simulation);
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
