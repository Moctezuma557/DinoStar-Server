<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use PhpMqtt\Client\MqttClient;
use PhpMqtt\Client\ConnectionSettings;
use Exception;

class MqttListen extends Command
{
    /**
     * El nombre y la firma del comando en Artisan.
     *
     * @var string
     */
    protected $signature = 'mqtt:listen';

    /**
     * Descripción del comando.
     *
     * @var string
     */
    protected $description = 'Escucha el broker MQTT local y procesa el paquete múltiple de pacientes';

    /**
     * Ejecución del comando.
     */
    public function handle(): int
    {
        $server   = env('MQTT_HOST', 'broker'); 
        $port     = (int) env('MQTT_PORT', 1883);
        $clientId = 'laravel_patient_listener_' . uniqid();
        $topic = env('MQTT_TOPIC', 'hospital/braquio/telemetria');

        $this->info("Conectando al broker MQTT en {$server}:{$port}...");

        try {
            $connectionSettings = (new ConnectionSettings)
                ->setKeepAliveInterval(60)
                ->setConnectTimeout(10);

            $mqtt = new MqttClient($server, $port, $clientId);
            $mqtt->connect($connectionSettings, true);

            $this->info("Conectado exitosamente. Suscribiéndose al topic: {$topic}");

            // Suscripción al tópico objetivo
            $mqtt->subscribe($topic, function (string $topic, string $message) {
                $this->info("\n--- Mega-paquete recibido [" . date('Y-m-d H:i:s') . "] ---");

                // 1. Decodificar el JSON a un arreglo asociativo de PHP
                $data = json_decode($message, true);

                if (json_last_error() !== JSON_ERROR_NONE) {
                    $this->error("Error al decodificar el payload JSON: " . json_last_error_msg());
                    return;
                }

                if (!is_array($data)) {
                    $this->warn("El payload recibido no es un arreglo válido.");
                    return;
                }

                // 2. Ciclo foreach para recorrer el arreglo de pacientes
                $totalPacientes = count($data);
                $this->line("Procesando <comment>{$totalPacientes}</comment> registros de pacientes:");

                foreach ($data as $index => $paciente) {
                    // 3. Procesar / Imprimir cada registro por separado
                    $this->procesarPaciente($paciente, $index + 1);
                }

                $this->info("--- Fin del procesamiento del paquete ---\n");
            }, 0);

            // Bucle principal de escucha continua
            $mqtt->loop(true);

        } catch (Exception $e) {
            $this->error("Excepción en MQTT Listener: " . $e->getMessage());
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    /**
     * Imprime la información individual de cada paciente.
     */
    private function procesarPaciente(array $paciente, int $numero): void
    {
        $this->line("----------------------------------------");
        $this->line("<fg=cyan>Paciente #{$numero}</fg=cyan>");

        foreach ($paciente as $key => $value) {
            if (is_array($value)) {
                $value = json_encode($value);
            }
            $this->line("  <fg=yellow>{$key}:</fg=yellow> {$value}");
        }
    }
}