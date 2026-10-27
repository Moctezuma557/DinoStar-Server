# NuevaLectura por Reverb — tarea #77

## Contrato para frontend

El backend guarda la lectura y sus alertas en una transacción. Cuando termina
la transacción exterior con éxito, `DB::afterCommit` publica `NuevaLectura`.
Un rollback exterior descarta tanto los registros como la publicación pendiente.
Un paquete rechazado o sin sesión activa no produce un evento.

| Elemento | Valor |
| --- | --- |
| Canal público | `pacientes` |
| Nombre en WebSocket/Pusher | `nueva.lectura` |
| Nombre para Laravel Echo | `.nueva.lectura` (con punto inicial) |
| Clase PHP | `App\Events\NuevaLectura` |
| Conexión local | Reverb en `localhost:8080` para clientes del equipo |
| Backend hacia Reverb en Docker | `reverb:8080` |
| Cola local Compose | `sync`; no requiere worker separado |

Ejemplo ilustrativo del objeto que recibe Echo:

```json
{
  "pacienteId": "cama-01",
  "gotasPorMin": 19.9,
  "volRestante": 45.2,
  "tiempoRestante": 136,
  "modo": "MICRO_GOTEO",
  "timestamp": 1792483200123,
  "alertas": ["FIN_BOLSA", "GOTEO_LENTO"],
  "lecturaId": 58,
  "sesionId": 17
}
```

- `pacienteId` conserva el significado anterior: **número de cama**, no ID de
  la tabla pacientes. No sustituirlo por `sesionId` ni `lecturaId`.
- `gotasPorMin`, `volRestante` y `tiempoRestante` conservan los valores del
  paquete validado. Si el emisor envía cadenas numéricas válidas, pueden
  conservar esa representación; el consumidor puede usar `Number(...)`.
- `timestamp` es el tiempo del dispositivo en **milisegundos Unix**. A diferencia
  del historial REST, no es una cadena ISO 8601.
- `modo` corresponde al paquete que produjo el evento (`MICRO_GOTEO` o
  `NORMAL_GOTEO`). Se conserva aunque otro paquete cambie después el modo de
  esa sesión; no se consulta de nuevo el modo al publicar un evento pendiente.
- `alertas` contiene únicamente los tipos generados por **esa lectura**, no
  todas las alertas pendientes de la sesión. Sin alertas es `[]`.
- Umbrales existentes: `FIN_BOLSA` con volumen < 50; `GOTEO_LENTO` con gotas < 20;
  `GOTEO_RAPIDO` con gotas > 60. Los valores exactos 50/20/60 no activan esos
  umbrales. Pueden coexistir volumen bajo y goteo lento o rápido.
- `lecturaId` y `sesionId` identifican los registros persistidos. En emisiones
  antiguas construidas manualmente con los seis argumentos originales pueden
  ser `null`; el constructor sigue siendo compatible.

Los seis campos originales, el canal y el nombre de evento se conservan.
Los tres campos nuevos son aditivos. No se requiere cambiar el listener actual
de `frontend/src/pages/dashboard.jsx` para seguir recibiendo lecturas.
La recepción del evento no demuestra que una tarjeta actualice visualmente el
modo: esa comprobación corresponde a la integración del frontend.

```js
const channel = echo.channel('pacientes');
channel.listen('.nueva.lectura', (lectura) => {
  console.log(lectura.pacienteId, lectura.lecturaId, lectura.alertas);
});
// Al desmontar la vista propietaria de la suscripción:
// echo.leaveChannel('pacientes');
```

Un cliente WebSocket sin Echo recibe un sobre Pusher con `event`, `channel` y
`data`; este último contiene el JSON serializado y debe deserializarse.
El canal sigue siendo público. Este cambio no introduce autorización ni
historial de mensajes en Reverb: para recuperar lecturas después de reconectar,
consultar `GET /api/sesiones/{id}/lecturas`.
El envío ocurre después del commit: un fallo de transporte en ese momento no
revierte los registros ya guardados. Esta tarea no agrega reintentos durables
ni garantiza entrega exactamente una vez al cliente.

## Verificación real en el proyecto local

Requisitos: Docker/Compose y la base local del proyecto ya inicializada. Desde
la raíz del repositorio, iniciar los servicios en PowerShell o Bash/Zsh:

```text
docker compose up -d --build database broker backend reverb
docker compose ps
```

El backend y Reverb deben estar saludables. Las credenciales `REVERB_APP_*`
deben coincidir en ambos contenedores. Se usan sus variables existentes; no
es necesario cambiar `.env` ni ejecutar seeders o migraciones para esta prueba.

### Windows — PowerShell

```powershell
& ./reverb/verify.ps1 -NuevaLectura
```

Alternativa directa si se prefiere enviar PHP por stdin:

```powershell
Get-Content -Raw ./reverb/verify-nueva-lectura.php | docker compose exec -T backend php
if ($LASTEXITCODE -ne 0) { throw 'Fallo la verificacion de NuevaLectura.' }
```

### macOS/Linux — Bash o Zsh

```bash
docker compose exec -T backend php < reverb/verify-nueva-lectura.php
echo $?
```

El código de salida debe ser 0. El script es el mismo en todos los sistemas;
no requiere instalar PHP, Node ni bibliotecas WebSocket en el anfitrión.

### Qué comprueba

1. Conexión WebSocket real y suscripción al canal `pacientes` del servicio Reverb.
2. Crea una enfermera, paciente y sesión sintéticos exclusivos de esa ejecución.
3. Procesa tres paquetes mediante `DataPacketService`: normal, volumen bajo con
   goteo lento y volumen bajo con goteo rápido, cambiando el modo
   MICRO → NORMAL → MICRO en la misma sesión.
4. Recibe los tres eventos reales `nueva.lectura` y compara sus campos e IDs
   con PostgreSQL; verifica tres lecturas y cuatro alertas.
5. Elimina únicamente los registros sintéticos que acaba de crear, incluso
   si la comprobación falla. Las secuencias de IDs pueden avanzar.

La prueba usa el broadcast configurado y requiere `APP_ENV=local/testing`,
`BROADCAST_CONNECTION=reverb` y `QUEUE_CONNECTION=sync`, como Compose local.
No publica por MQTT: verifica específicamente el recorrido servicio → Reverb
→ cliente WebSocket. La suscripción de prueba se ejecuta dentro del backend y
accede a Reverb por la red Docker; no acredita por sí sola el acceso de un
navegador externo ni la representación visual de las tarjetas.

Salida esperada (los IDs, cama y timestamp varían):

```text
OK: cliente WebSocket suscrito a pacientes.
RECIBIDO pacientes / nueva.lectura: {... "alertas":[] ...}
RECIBIDO pacientes / nueva.lectura: {... "alertas":["FIN_BOLSA","GOTEO_LENTO"] ...}
RECIBIDO pacientes / nueva.lectura: {... "alertas":["FIN_BOLSA","GOTEO_RAPIDO"] ...}
OK: 3 lecturas y 4 alertas SQL; 3 eventos reales DataPacketService -> Reverb -> WebSocket.
OK: cambios MICRO -> NORMAL -> MICRO confirmados en la misma sesion y en los eventos.
OK: registros sinteticos de esta ejecucion retirados.
```

Conservar esta salida o una captura como evidencia de recepción. Si el proceso
se interrumpe forzosamente, revisar los registros `rv77-*` de esa ejecución:
no borrar pacientes o sesiones ajenos para limpiar la prueba.

`reverb/verify.ps1` sin `-NuevaLectura` conserva su prueba genérica anterior de
conexión por `localhost:8080`. Esa comprobación adicional sirve para confirmar
el puerto publicado en Windows, pero no sustituye la prueba de lectura real.

## Pruebas automatizadas en PostgreSQL aislado

`backend/tests/Feature/NuevaLecturaBroadcastTest.php` prueba el commit exterior,
rollback, payload, tipos de alerta, umbrales, sesiones ausentes/pausadas,
validación y compatibilidad del constructor. También prueba dos lotes para diez
camas con ambos modos, cambios de modo en ambas direcciones, conservación de
las filas de lecturas anteriores y recuperación después de fallar al guardar
una alerta. Los fallos revierten lectura, alertas y cambio de modo, sin publicar
un evento pendiente en un commit posterior. Cada caso prepara sus propios
pacientes y sesiones; no depende de `DemoSeeder`. Intercepta los trabajos de
broadcast; la prueba WebSocket anterior cubre el transporte real.

Cada caso crea y elimina una base `dinostar77_<aleatorio>` en el PostgreSQL de
pruebas. Esto permite hacer commits reales sin dejar datos en otras pruebas.
No ejecutar contra la base habitual del proyecto.

Crear una vez el contenedor aislado (comando válido en ambos shells):

```text
docker run -d --name dinostar-77-tests-db -e POSTGRES_DB=dinostar_issue22_test -e POSTGRES_USER=postgres -e POSTGRES_PASSWORD=issue22-test-only postgres:15 -p 55432
```

Si ya existe, usar `docker start dinostar-77-tests-db`. Esperar a que indique
`accepting connections`:

```text
docker exec dinostar-77-tests-db pg_isready -U postgres -p 55432 -d dinostar_issue22_test
```

### Windows — PowerShell

```powershell
$rutaProyecto = (Get-Location).Path
$imagenBackend = docker inspect --format '{{.Image}}' dinostar-backend
docker run --rm `
  --network container:dinostar-77-tests-db `
  --volumes-from dinostar-backend `
  --mount "type=bind,source=$rutaProyecto/database,target=/var/www/database,readonly" `
  --entrypoint php -w /var/www/html `
  -e DINOSTAR_MIGRATION_TEST=1 -e APP_ENV=testing `
  -e APP_KEY=base64:MDEyMzQ1Njc4OWFiY2RlZjAxMjM0NTY3ODlhYmNkZWY= `
  -e BROADCAST_CONNECTION=null `
  "$imagenBackend" vendor/phpunit/phpunit/phpunit --do-not-cache-result --filter NuevaLecturaBroadcastTest
```

### macOS/Linux — Bash o Zsh

```bash
IMAGEN_BACKEND=$(docker inspect --format '{{.Image}}' dinostar-backend)
docker run --rm \
  --network container:dinostar-77-tests-db \
  --volumes-from dinostar-backend \
  --mount "type=bind,source=$(pwd)/database,target=/var/www/database,readonly" \
  --entrypoint php -w /var/www/html \
  -e DINOSTAR_MIGRATION_TEST=1 -e APP_ENV=testing \
  -e APP_KEY=base64:MDEyMzQ1Njc4OWFiY2RlZjAxMjM0NTY3ODlhYmNkZWY= \
  -e BROADCAST_CONNECTION=null \
  "$IMAGEN_BACKEND" vendor/phpunit/phpunit/phpunit --do-not-cache-result --filter NuevaLecturaBroadcastTest
```

Para ejecutar toda la suite, quitar `--filter NuevaLecturaBroadcastTest`.
Las credenciales y APP_KEY anteriores son exclusivas de pruebas.

## Alcance de la entrega

Se mantienen las reglas actuales de `Develop` para selección de sesión y
ajuste del modo. #77 agrega los tipos de alerta y los IDs al evento, cobertura
del broadcast y una verificación reproducible; no redefine esas reglas.
No se modifica el frontend, el seeder, los endpoints ni las migraciones.

### Comportamiento conservado y decisiones pendientes

La ampliación a diez camas y la aceptación de un modo diferente se conservan
según el comportamiento integrado en `Develop` (`7660880`). Las pruebas de #77
comprueban su compatibilidad con el broadcast; no cambian las reglas del servicio.

| Situación | Comportamiento que verifica #77 |
| --- | --- |
| Diez camas registradas con sesión activa | Cada paquete guarda una lectura en su sesión y publica un evento después del commit; diez no se agrega como límite del backend. |
| Cambio válido de modo | Actualiza la misma sesión y envía el modo recibido; conserva las filas de lecturas anteriores. |
| Cambio de modo sin umbrales de alerta | `alertas` es `[]`; el mensaje de log existente no se convierte en una alerta de base de datos. |
| Sesión ausente o pausada | No guarda lecturas ni publica; #77 no agrega activación automática. |
| Error de persistencia o rollback exterior | Revierte lectura, alertas y modo; no publica esa lectura. |

Conservar las filas de lecturas no equivale a conservar su modo histórico:
`lecturas` no almacena ese campo y el modo de la sesión es mutable. Agregarlo,
cambiar la delimitación de sesiones o introducir una alerta específica por
cambio de modo requiere otra decisión de contrato y no forma parte de #77.

También quedan fuera de esta entrega la compatibilidad del seeder al repetirlo
después de cambiar modos, el comando `demo:configurar-mqtt` que aún espera dos
sesiones, y la opción `mqtt:listen --simulador` cuya activación automática dejó
de invocarse en el servicio. Las pruebas anteriores que cubren estos contratos
permanecen visibles; no se eliminan ni se marcan como omitidas para aprobar #77.

### Resultado verificado en Windows, 22/10/2026

| Ejecución | Resultado |
| --- | --- |
| Nuevos casos de #77 | 13 pruebas, 329 aserciones, aprobadas |
| WebSocket real en Compose | 3 eventos recibidos; 3 lecturas y 4 alertas contrastadas; cambios MICRO → NORMAL → MICRO; limpieza completada y totales de tablas conservados |
| Suite completa con #77 | 96 pruebas, 805 aserciones; 5 errores y 17 fallos |
| Suite del código base `Develop` (`7660880`), sin #77 | 83 pruebas, 476 aserciones; los mismos 5 errores y 17 fallos |

Se compararon los mismos casos fallidos con las versiones originales de
`DataPacketService` y `NuevaLectura` del commit base montadas en el contenedor
temporal, excluyendo la clase de pruebas nueva. El resto del código de
aplicación y de las pruebas anteriores se mantuvo idéntico.
La ejecución ampliada repite los mismos casos fallidos del diagnóstico base;
los casos de `PacienteApiTest` y `SesionLecturaApiTest` siguen aprobados.

Los fallos anteriores afectan las expectativas del seeder/demo (ahora crea
10 sesiones y 50 lecturas frente a las 2 sesiones/10 lecturas esperadas por
varias pruebas) y las reglas de modo/activación del simulador que cambiaron
en `Develop`. Las expectativas de dos sesiones y rechazo de cambios de modo
deben adaptarse al comportamiento comunicado por el equipo. Las decisiones
pendientes sobre historial, activación y configuración demo se detallan arriba;
su corrección se mantiene separada de #77. No se alteraron ni se desactivaron
esas pruebas para obtener una suite verde.

La verificación de #77 pasó; **la suite completa no está aprobada**. No se
atribuyen estos fallos a #77 porque también se reproducen sin sus cambios.
La ejecución en macOS/Linux queda pendiente de realizar en esos equipos.
