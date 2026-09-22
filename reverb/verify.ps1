param([string] $AppKey = 'dinostar-local')

$ErrorActionPreference = 'Stop'
$socket = [Net.WebSockets.ClientWebSocket]::new()
$deadline = [Threading.CancellationTokenSource]::new(20000)

function Read-ReverbMessage {
    $buffer = New-Object byte[] 16384
    $message = [Text.StringBuilder]::new()
    do {
        $result = $socket.ReceiveAsync([ArraySegment[byte]]::new($buffer), $deadline.Token).GetAwaiter().GetResult()
        if ($result.MessageType -eq [Net.WebSockets.WebSocketMessageType]::Close) {
            throw 'Reverb cerro la conexion antes de completar la prueba.'
        }
        [void] $message.Append([Text.Encoding]::UTF8.GetString($buffer, 0, $result.Count))
    } until ($result.EndOfMessage)
    return ($message.ToString() | ConvertFrom-Json)
}

try {
    $encodedKey = [Uri]::EscapeDataString($AppKey)
    [void] $socket.ConnectAsync([Uri]"ws://localhost:8080/app/${encodedKey}?protocol=7&client=verification&version=1.0", $deadline.Token).GetAwaiter().GetResult()
    $connected = Read-ReverbMessage
    if ($connected.event -ne 'pusher:connection_established') { throw 'No se confirmo la conexion WebSocket.' }

    $subscribe = [Text.Encoding]::UTF8.GetBytes('{"event":"pusher:subscribe","data":{"channel":"dinostar-verification"}}')
    [void] $socket.SendAsync([ArraySegment[byte]]::new($subscribe), [Net.WebSockets.WebSocketMessageType]::Text, $true, $deadline.Token).GetAwaiter().GetResult()
    $subscribed = Read-ReverbMessage
    if ($subscribed.event -ne 'pusher_internal:subscription_succeeded') { throw 'No se confirmo la suscripcion.' }

    $publish = 'require "vendor/autoload.php"; $app = require "bootstrap/app.php"; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); Illuminate\Support\Facades\Broadcast::connection("reverb")->broadcast(["dinostar-verification"], "verification.passed", ["message" => "backend-to-reverb"]);'
    # Send PHP through stdin so Windows PowerShell preserves its quotes.
    ('<?php ' + $publish) | docker exec -i dinostar-backend php
    if ($LASTEXITCODE -ne 0) { throw 'El backend no pudo publicar el evento.' }

    $event = Read-ReverbMessage
    $payload = $event.data | ConvertFrom-Json
    if ($event.event -ne 'verification.passed' -or $payload.message -ne 'backend-to-reverb') {
        throw 'El cliente no recibio el evento esperado.'
    }
    Write-Output 'OK: conexion WebSocket, suscripcion y evento Laravel -> Reverb -> cliente.'
} finally {
    $socket.Dispose()
    $deadline.Dispose()
}
