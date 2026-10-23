<?php

// Run from the backend working directory using PHP stdin; see NUEVA_LECTURA.md.
use App\Models\Paciente;
use App\Models\User;
use App\Services\DataPacketService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

function check(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function writeAll($socket, string $data): void
{
    while ($data !== '') {
        $written = fwrite($socket, $data);
        check($written !== false && $written > 0, 'No se pudo escribir al WebSocket.');
        $data = substr($data, $written);
    }
}

function readBytes($socket, int $length): string
{
    $data = '';
    while (strlen($data) < $length) {
        $part = fread($socket, $length - strlen($data));
        check($part !== false && $part !== '', 'WebSocket cerrado o tiempo de espera agotado.');
        $data .= $part;
    }

    return $data;
}

function sendFrame($socket, string $data, int $opcode = 1): void
{
    $length = strlen($data);
    check($length < 65536, 'Mensaje de verificacion demasiado largo.');
    $header = chr(0x80 | $opcode).($length < 126 ? chr(0x80 | $length) : chr(0x80 | 126).pack('n', $length));
    $mask = random_bytes(4);
    $masked = $data;
    for ($i = 0; $i < $length; $i++) {
        $masked[$i] = $data[$i] ^ $mask[$i % 4];
    }
    writeAll($socket, $header.$mask.$masked);
}

function receiveMessage($socket): array
{
    $data = '';
    do {
        $header = readBytes($socket, 2);
        $opcode = ord($header[0]) & 15;
        $final = (ord($header[0]) & 128) !== 0;
        check((ord($header[1]) & 128) === 0, 'Frame de servidor inesperadamente enmascarado.');
        $length = ord($header[1]) & 127;
        if ($length === 126) {
            $length = unpack('n', readBytes($socket, 2))[1];
        } elseif ($length === 127) {
            $parts = unpack('Nhigh/Nlow', readBytes($socket, 8));
            check($parts['high'] === 0 && $parts['low'] <= 1048576, 'Frame demasiado grande.');
            $length = $parts['low'];
        }
        $payload = readBytes($socket, $length);
        check($opcode !== 8, 'Reverb cerro el WebSocket.');
        if ($opcode === 9) {
            sendFrame($socket, $payload, 10);

            continue;
        }
        if ($opcode === 10) {
            continue;
        }
        check(in_array($opcode, [0, 1], true), 'Se esperaba un frame de texto.');
        $data .= $payload;
        check(strlen($data) <= 1048576, 'Mensaje demasiado grande.');
        if ($final) {
            return json_decode($data, true, 512, JSON_THROW_ON_ERROR);
        }
    } while (true);
}

function waitEvent($socket, string $expected, ?string $bed = null): array
{
    $deadline = microtime(true) + 15;
    while (microtime(true) < $deadline) {
        $message = receiveMessage($socket);
        if (($message['event'] ?? '') === 'pusher:ping') {
            sendFrame($socket, '{"event":"pusher:pong","data":{}}');

            continue;
        }
        check(($message['event'] ?? '') !== 'pusher:error', 'Reverb devolvio un error de protocolo.');
        if (($message['event'] ?? '') !== $expected) {
            continue;
        }
        $payload = is_string($message['data']) ? json_decode($message['data'], true, 512, JSON_THROW_ON_ERROR) : $message['data'];
        if ($bed !== null && ($payload['pacienteId'] ?? null) !== $bed) {
            continue;
        }
        if ($bed !== null) {
            check(($message['channel'] ?? '') === 'pacientes', 'Canal incorrecto.');
        }

        return $payload;
    }
    throw new RuntimeException('No se recibio el evento esperado.');
}

$socket = null;
$nurse = null;
$patient = null;
$session = null;
$failed = false;
try {
    check($app->environment(['local', 'testing']), 'Solo ejecutar en local/testing.');
    check(config('broadcasting.default') === 'reverb', 'BROADCAST_CONNECTION debe ser reverb.');
    check(config('queue.default') === 'sync', 'Esta verificacion requiere QUEUE_CONNECTION=sync, como Compose local.');
    $options = config('broadcasting.connections.reverb.options');
    $host = $options['host'];
    $port = $options['port'];
    $transport = $options['scheme'] === 'https' ? 'tls' : 'tcp';
    $socket = stream_socket_client("{$transport}://{$host}:{$port}", $errno, $error, 10);
    check($socket !== false, 'No se pudo conectar a Reverb.');
    stream_set_timeout($socket, 15);
    $key = base64_encode(random_bytes(16));
    $appKey = rawurlencode(config('broadcasting.connections.reverb.key'));
    $path = "/app/{$appKey}?protocol=7&client=dinostar-verification&version=1.0";
    writeAll($socket, "GET {$path} HTTP/1.1\r\nHost: {$host}:{$port}\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Key: {$key}\r\nSec-WebSocket-Version: 13\r\n\r\n");
    $headers = '';
    while (! str_ends_with($headers, "\r\n\r\n")) {
        $headers .= readBytes($socket, 1);
        check(strlen($headers) <= 16384, 'Cabeceras HTTP demasiado grandes.');
    }
    check(str_starts_with($headers, 'HTTP/1.1 101'), 'Reverb no acepto el upgrade WebSocket.');
    preg_match('/Sec-WebSocket-Accept:\s*([^\r\n]+)/i', $headers, $accept);
    check(($accept[1] ?? '') === base64_encode(sha1($key.'258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true)), 'Handshake WebSocket invalido.');
    waitEvent($socket, 'pusher:connection_established');
    sendFrame($socket, '{"event":"pusher:subscribe","data":{"channel":"pacientes"}}');
    waitEvent($socket, 'pusher_internal:subscription_succeeded');
    echo "OK: cliente WebSocket suscrito a pacientes.\n";

    $suffix = bin2hex(random_bytes(6));
    $bed = 'rv77-'.$suffix;
    // Only this run's synthetic records are created and removed; no seeder or migration.
    $nurse = User::create([
        'nombre' => 'Verificacion Reverb 77', 'email' => $bed.'@example.invalid',
        'password' => bin2hex(random_bytes(32)), 'rol' => 'ENFERMERA', 'turno' => 'MATUTINO', 'activo' => true,
    ]);
    $patient = Paciente::create(['nombre' => 'Verificacion Reverb 77', 'numero_cama' => $bed, 'sala' => 'Pruebas Reverb', 'activo' => true]);
    $session = $patient->sesiones()->create([
        'enfermera_id' => $nurse->id, 'vol_total' => 500, 'tipo_solucion' => 'Salina 0.9%',
        'modo_goteo' => 'MICRO_GOTEO', 'estado' => 'ACTIVA', 'inicio' => now(),
    ]);
    $timestamp = (int) floor(microtime(true) * 1000);
    $cases = [
        [32.5, 450.0, 830, 'MICRO_GOTEO', []],
        [19.9, 45.2, 136, 'NORMAL_GOTEO', ['FIN_BOLSA', 'GOTEO_LENTO']],
        [60.1, 40.0, 40, 'MICRO_GOTEO', ['FIN_BOLSA', 'GOTEO_RAPIDO']],
    ];
    foreach ($cases as $index => [$drops, $volume, $minutes, $mode, $types]) {
        $packet = ['pacienteId' => $bed, 'gotasPorMin' => $drops, 'volRestante' => $volume,
            'tiempoRestante' => $minutes, 'modo' => $mode, 'timestamp' => $timestamp + $index];
        app(DataPacketService::class)->procesar($packet);
        $payload = waitEvent($socket, 'nueva.lectura', $bed);
        $reading = $session->lecturas()->orderByDesc('id')->firstOrFail();
        foreach ($packet as $name => $value) {
            check(array_key_exists($name, $payload) && $payload[$name] == $value, 'Campo incompatible: '.$name);
        }
        check(($payload['alertas'] ?? null) === $types, 'Tipos de alerta incorrectos.');
        check(($payload['lecturaId'] ?? null) === $reading->id, 'La lectura emitida no coincide con SQL.');
        check(($payload['sesionId'] ?? null) === $session->id, 'La sesion emitida no coincide con SQL.');
        check($session->fresh()->modo_goteo === $mode, 'El modo de la sesion no coincide con el evento.');
        check($reading->gotas_por_min === $drops && (float) $reading->vol_restante === $volume, 'Valores SQL incorrectos.');
        check($reading->tiempo_restante_min === $minutes && (int) $reading->timestamp_dispositivo->getTimestampMs() === $packet['timestamp'], 'Tiempo SQL incorrecto.');
        echo 'RECIBIDO pacientes / nueva.lectura: '.json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
    }
    check($session->lecturas()->count() === 3, 'Cantidad de lecturas incorrecta.');
    check($patient->sesiones()->count() === 1, 'El cambio de modo reemplazo la sesion.');
    check($session->alertas()->orderBy('id')->pluck('tipo')->all() === ['FIN_BOLSA', 'GOTEO_LENTO', 'FIN_BOLSA', 'GOTEO_RAPIDO'], 'Alertas SQL incorrectas.');
    echo "OK: 3 lecturas y 4 alertas SQL; 3 eventos reales DataPacketService -> Reverb -> WebSocket.\n";
    echo "OK: cambios MICRO -> NORMAL -> MICRO confirmados en la misma sesion y en los eventos.\n";
} catch (Throwable $exception) {
    $failed = true;
    fwrite(STDERR, 'ERROR: '.$exception->getMessage()."\n");
} finally {
    if (is_resource($socket)) {
        fclose($socket);
    }
    try {
        DB::transaction(function () use ($session, $patient, $nurse): void {
            $session?->alertas()->delete();
            $session?->lecturas()->delete();
            $session?->delete();
            $patient?->delete();
            $nurse?->delete();
        });
        echo "OK: registros sinteticos de esta ejecucion retirados.\n";
    } catch (Throwable $exception) {
        $failed = true;
        fwrite(STDERR, 'ERROR de limpieza; conservar IDs para revision: '.json_encode(['paciente' => $patient?->id, 'sesion' => $session?->id, 'enfermera' => $nurse?->id])."\n");
    }
}
exit($failed ? 1 : 0);
