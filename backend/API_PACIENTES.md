# API de pacientes

Esta entrega agrega registro, listado y detalle sobre los modelos y migraciones existentes. No modifica el simulador, los seeders ni DataPacketService. `tipo_solucion` ya pertenece al `$fillable` de Sesion y a su migración anterior.

## Alcance y decisiones

- Se requiere `enfermera_id` en el POST, acordado para esta entrega. Debe existir en `usuarios`, tener rol `ENFERMERA` y estar activa.
- Esta rama todavía no implementa autenticación. El ID asigna la sesión; **no acredita la identidad del solicitante**. Los filtros tampoco son autorización. Usar datos ficticios en el entorno local; integrar autenticación y permisos antes de publicar esta API con datos reales.
- Sesión actual significa `ACTIVA`. El listado y detalle excluyen pacientes inactivos y sesiones pausadas/finalizadas. El detalle devuelve 404 cuando no hay sesión activa.
- Una cama está reservada si existe un paciente activo con ese número o una sesión ACTIVA/PAUSADA, incluso si el paciente está inactivo. En ese caso POST responde 409 y no cambia datos existentes. Esto también aplica a pacientes demo activos sin sesión: el endpoint registra **nuevos** pacientes, no transforma los registros del seeder.
- Los números de cama se comparan sin distinguir mayúsculas y son únicos entre pacientes activos de todas las salas para esta API: MQTT identifica la cama por `pacienteId` sin incluir sala. El número guardado debe coincidir exactamente con el enviado por el dispositivo, por ejemplo `cama-01`.
- No se da de alta ni se desactiva automáticamente a un paciente anterior. Una cama con paciente inactivo y únicamente sesiones cerradas puede reutilizarse creando otro paciente y conservando el historial anterior.
- El alta usa una transacción y un bloqueo por cama en PostgreSQL. Dos solicitudes simultáneas de esta API para la misma cama no crean dos pacientes. Los bloqueos sobre pacientes coordinan las consultas con las actualizaciones de sesión del listener.

## Arranque (Windows, macOS y Linux)

Desde la raíz del repositorio, con Docker Desktop/Engine en ejecución:

```text
docker compose up -d --build --wait database broker backend
docker compose exec backend php artisan migrate --no-interaction
docker compose exec backend php artisan route:list --path=api
```

No ejecutar `migrate:fresh`, borrar volúmenes ni volver a sembrar datos para probar esta API. Para obtener una enfermera ya existente, ejecutar en pgAdmin sobre `dinostar_rex`:

```sql
SELECT id, nombre FROM usuarios
WHERE rol = 'ENFERMERA' AND activo = true ORDER BY id;
```

El Dockerfile inicia `artisan serve` con `--no-reload`: así el proceso HTTP conserva las variables de Compose (`DB_CONNECTION=pgsql`, `DB_HOST=database`, etc.) aunque exista un `backend/.env` local configurado para SQLite. Sin esa opción, Artisan puede consultar PostgreSQL mientras las peticiones HTTP consultan SQLite. La opción desactiva la recarga al cambiar `.env`, no la lectura de archivos PHP; al modificar variables de Compose hay que recrear el contenedor.

Si una imagen anterior responde `Connection: sqlite` / `no such table: usuarios`, reconstruir solo el backend desde la raíz:

```text
docker compose up -d --build --no-deps --wait backend
```

Después comprobar `GET /api/pacientes` por HTTP; que `/up` esté saludable o que `migrate:status` funcione no demuestra que el servidor HTTP esté usando la misma conexión.

Si no existe ninguna enfermera activa, se requiere registrarla por el mecanismo del equipo antes del POST. La API no inventa usuarios ni utiliza una enfermera por defecto.

## Contrato HTTP

Base local: `http://localhost:8000`. Enviar `Accept: application/json` y, para POST, `Content-Type: application/json`.

### POST /api/pacientes

Todos estos campos son obligatorios:

| Campo | Validación |
| --- | --- |
| nombre | Texto no vacío, máximo 150 caracteres |
| numero_cama | Texto no vacío, máximo 20 caracteres, cama libre |
| sala | Texto no vacío, máximo 50 caracteres |
| vol_total | Número mayor que 0 y hasta 5000 mL, máximo 2 decimales |
| modo_goteo | `NORMAL_GOTEO` o `MICRO_GOTEO` |
| tipo_solucion | Texto no vacío, máximo 255 caracteres |
| enfermera_id | ID entero de una enfermera activa existente; admite IDs negativos de datos demo |

Ejemplo (sustituir `enfermera_id` y usar una cama libre):

```json
{
  "nombre": "Paciente API prueba",
  "numero_cama": "api-01",
  "sala": "Pruebas API",
  "vol_total": 500,
  "modo_goteo": "MICRO_GOTEO",
  "tipo_solucion": "Salina 0.9%",
  "enfermera_id": -35001
}
```

Responde **201**, encabezado `Location` con la URL del detalle y `{"data": {...}}` con el mismo formato del detalle. La sesión comienza ACTIVA, con `inicio` del servidor, `fin: null` y `lecturas: []`. Campos adicionales como `estado`, `activo` o `paciente_id` no permiten modificar esos valores.

### GET /api/pacientes

Responde **200** con `data` como arreglo; sin coincidencias, `{"data": []}`. Cada elemento incluye:

```json
{
  "data": [
    {
      "id": 123,
      "nombre": "Paciente API prueba",
      "numero_cama": "api-01",
      "sala": "Pruebas API",
      "tipo_solucion": "Salina 0.9%",
      "modo_goteo": "MICRO_GOTEO",
      "vol_total": "500.00",
      "enfermera_id": -35001
    }
  ]
}
```

Filtros opcionales, combinables, aplicados a la sesión activa:

```text
GET /api/pacientes?nombre=Paciente
GET /api/pacientes?enfermera_id=-35001
GET /api/pacientes?nombre=Paciente&enfermera_id=-35001
```

`nombre` busca una parte literal del nombre sin distinguir mayúsculas; `%` y `_` no son comodines. Codificar los parámetros con URLSearchParams. `enfermera_id` inexistente devuelve lista vacía; formato inválido devuelve 422. Orden: número de cama, después ID. Esta entrega retorna la lista completa, sin paginación.

### GET /api/pacientes/{id}

Responde **200** con `data` y estas propiedades:

- Paciente: `id`, `nombre`, `numero_cama`, `sala`, `activo`, `created_at`, `updated_at`.
- `sesion`: `id`, `paciente_id`, `enfermera_id`, `vol_total`, `tipo_solucion`, `modo_goteo`, `estado`, `inicio`, `fin`, `created_at`, `updated_at`, `lecturas`.
- Cada lectura: `id`, `sesion_id`, `gotas_por_min`, `vol_restante`, `tiempo_restante_min`, `timestamp_dispositivo`, `created_at`.

Solo incluye lecturas de esa sesión activa, ordenadas por timestamp del dispositivo y luego ID. No mezcla sesiones anteriores. Sesiones antiguas pueden tener `tipo_solucion: null`. Los decimales de volumen se serializan como texto (`"500.00"`) para conservar precisión; las fechas se devuelven en ISO 8601 UTC. Las alertas conservan su flujo actual en la BD y no forman parte de este endpoint.

### PATCH /api/pacientes/{id} — editar (#99)

Enviar JSON con uno o más campos: `nombre` (máximo 150 caracteres),
`numero_cama` (20) y `sala` (50). Ejemplo: `{"nombre":"Ana Pérez","sala":"Sala B"}`.
Los campos omitidos se conservan; un campo editable presente no admite null ni
texto en blanco. Campos adicionales se ignoran; si no hay ninguno editable,
responde **422**. No permite cambiar `activo`, IDs ni datos de la sesión.

Responde **200** con el paciente editado, incluso si no tiene sesión:

```json
{"data":{"id":123,"nombre":"Ana Pérez","numero_cama":"api-01","sala":"Sala B","activo":true}}
```

La cama se comprueba con la misma regla y bloqueo por número que POST:
no distingue mayúsculas, es global entre salas y excluye al propio paciente.
Una cama reservada por otro paciente activo o por otra sesión en curso da
**409**, sin aplicar ninguno de los cambios. Al cambiar de cama, los nuevos
paquetes del dispositivo deben usar ese número; el historial conserva sus IDs.

### DELETE /api/pacientes/{id} — baja lógica (#99)

| Situación | Respuesta y efecto |
| --- | --- |
| Sin sesión en curso | **204**, sin cuerpo. Marca al paciente como inactivo. |
| Sesión `ACTIVA` o `PAUSADA`, sin confirmación o con `false` | **409**. No modifica al paciente ni a la sesión. |
| Sesión en curso con JSON `{"finalizar_sesion":true}` | **204**. Cambia esa sesión a `FINALIZADA`, guarda `fin` con la hora del servidor y marca al paciente como inactivo en la misma transacción. |
| Confirmación inválida, por ejemplo `"true"`, `1` o `null` | **422**. El campo, si se envía, debe ser booleano JSON. |
| Paciente inexistente o ya inactivo | **404** con una solicitud válida, también al repetir la baja o intentar editarlo. |

La baja no borra filas ni resuelve alertas. El paciente deja de aparecer en
`GET /api/pacientes`; el historial de cada sesión sigue disponible en
`GET /api/sesiones/{id}/lecturas`. No se necesita cambiar el esquema.

**Comprobación en Postman (Windows/macOS/Linux):** usar exclusivamente un
paciente ficticio creado para esta prueba con el POST anterior. Guardar
`data.id` como `paciente_id` y `data.sesion.id` como `sesion_id`. Enviar
`Accept: application/json` y usar Body → raw → JSON cuando corresponda.

1. `PATCH {{base_url}}/api/pacientes/{{paciente_id}}` con `{"sala":"Sala B"}`:
   **200**, solo cambia la sala. Con la cama de otro paciente activo: **409**.
2. `DELETE {{base_url}}/api/pacientes/{{paciente_id}}` sin cuerpo: **409**
   porque el POST abrió una sesión. El detalle aún muestra la misma sesión.
3. Repetir DELETE con `{"finalizar_sesion":true}`: **204**, sin cuerpo.
4. En el listado activo ya no está su ID. El historial del `sesion_id` anterior
   devuelve **200** con sus lecturas (o `data: []` si no había ninguna).

Para repetir las 14 peticiones con comprobaciones automáticas, importar
[`pacientes-b1.postman_collection.json`](pacientes-b1.postman_collection.json)
en Postman y seleccionar **No Environment**. En la base local, comprobar que
la enfermera de la petición 01 (`-35001`) está activa y que la cama de la 05
(`CAMA-01`) está ocupada. Si difieren, ajustar esos cuerpos antes de empezar.
Enviar **01** una sola vez. La colección guarda los IDs devueltos.

Desde la raíz del repositorio, sustituir `CAMA_DEL_POST` por el valor exacto
de `data.numero_cama` y ejecutar una sola vez en PowerShell, Bash o zsh:

```text
docker compose exec -T -e XDG_CONFIG_HOME=/tmp -e CAMA_B1=CAMA_DEL_POST backend php artisan tinker --execute="app(\App\Services\DataPacketService::class)->procesar(['pacienteId'=>getenv('CAMA_B1'),'gotasPorMin'=>19.9,'volRestante'=>45.2,'tiempoRestante'=>136,'modo'=>'MICRO_GOTEO','timestamp'=>now()->getTimestampMs()]);"
```

Enviar **02 a 14** en orden. La **03** debe devolver una lectura antes de
continuar. La **11** confirma la baja de ese paciente ficticio y la **14**
compara el historial con el de la **03**.

### Errores

| HTTP | Causa | Respuesta |
| --- | --- | --- |
| 404 | GET de detalle: paciente inexistente, inactivo o sin sesión activa | `{"message":"Paciente no encontrado o sin sesión activa."}` |
| 404 | PATCH/DELETE: paciente inexistente o inactivo | `{"message":"Paciente no encontrado o inactivo."}` |
| 409 | Cama reservada | `{"message":"La cama ya está ocupada por un paciente activo o una sesión en curso."}` |
| 422 | Validación de campos/filtros | `message` y `errors`, con arreglos de mensajes por campo |

Un error al insertar la sesión revierte también el paciente. No reintentar a ciegas un POST cuyo resultado se desconoce: consultar antes si se registró, pues el siguiente intento puede responder 409.

## Prueba manual en Windows PowerShell

Desde la raíz, después del arranque. Sustituir `-35001` por el ID consultado si es distinto. Se crea un paciente ficticio persistente con cama nueva.

```powershell
$api = 'http://localhost:8000/api/pacientes'
$enfermeraId = -35001
$camaApi = 'api-' + (Get-Date -Format 'MMddHHmmss')
$datos = @{
    nombre = 'Paciente API prueba'
    numero_cama = $camaApi
    sala = 'Pruebas API'
    vol_total = 500
    modo_goteo = 'MICRO_GOTEO'
    tipo_solucion = 'Salina 0.9%'
    enfermera_id = $enfermeraId
} | ConvertTo-Json
$creado = Invoke-RestMethod -Method Post -Uri $api -ContentType 'application/json; charset=utf-8' -Body $datos
$creado | ConvertTo-Json -Depth 10
Invoke-RestMethod -Uri $api | ConvertTo-Json -Depth 10
Invoke-RestMethod -Uri ($api + '?nombre=Paciente&enfermera_id=' + $enfermeraId) | ConvertTo-Json -Depth 10
Invoke-RestMethod -Uri ($api + '/' + $creado.data.id) | ConvertTo-Json -Depth 10
```

Repetir el POST con los mismos `$datos` debe dar **409**; PowerShell lo muestra como excepción HTTP esperada. Un POST con `{}` debe dar **422**; un GET a `/api/pacientes/999999999999999999` debe dar **404**. La colección Postman permite inspeccionar esos códigos y cuerpos sin manejar excepciones de PowerShell.

## Prueba manual en macOS/Linux

Ejecutar desde una terminal. Sustituir la enfermera si corresponde. El archivo temporal solo contiene datos ficticios de la solicitud.

```bash
api='http://localhost:8000/api/pacientes'
enfermera_id=-35001
cama_api="api-$(date +%m%d%H%M%S)"
solicitud=$(mktemp)
cat > "$solicitud" <<EOF
{"nombre":"Paciente API prueba","numero_cama":"$cama_api","sala":"Pruebas API","vol_total":500,"modo_goteo":"MICRO_GOTEO","tipo_solucion":"Salina 0.9%","enfermera_id":$enfermera_id}
EOF
curl -i -H 'Accept: application/json' -H 'Content-Type: application/json' --data-binary "@$solicitud" "$api"
curl -i -H 'Accept: application/json' "$api"
curl -i -G -H 'Accept: application/json' --data-urlencode 'nombre=Paciente' --data-urlencode "enfermera_id=$enfermera_id" "$api"
```

Copiar el ID de `data.id` del POST para consultar el detalle:

```bash
paciente_id=123 # Sustituir por el ID devuelto
curl -i -H 'Accept: application/json' "$api/$paciente_id"
# El mismo POST debe devolver 409:
curl -i -H 'Accept: application/json' -H 'Content-Type: application/json' --data-binary "@$solicitud" "$api"
curl -i -H 'Accept: application/json' "$api/999999999999999999"
curl -i -H 'Accept: application/json' -H 'Content-Type: application/json' -d '{}' "$api"
rm -- "$solicitud"
```

## Pruebas con Postman

Importar `backend/pacientes.postman_collection.json`. Configurar variables de colección `base_url` y `enfermera_id`. Ejecutar en orden las ocho solicitudes. La primera genera una cama `api-...` libre, guarda `paciente_id` y deja los datos ficticios en la base; las demás reutilizan esos valores. Cada ejecución completa crea otro paciente de prueba.

Los códigos esperados, en orden, son 201, 409, 200, 200, 200, 404, 422 y 422. Revisar el cuerpo de cada respuesta y la pestaña Test Results. Los rechazos 409, 404 y 422 son resultados correctos de las solicitudes negativas incluidas en la colección.

## Verificar en pgAdmin

Sustituir `123` por `data.id`, sin modificar registros:

```sql
SELECT p.id, p.nombre, p.numero_cama, p.activo,
       s.id AS sesion_id, s.enfermera_id, s.estado,
       s.tipo_solucion, s.modo_goteo, s.vol_total
FROM pacientes p
JOIN sesiones s ON s.paciente_id = p.id
WHERE p.id = 123;

SELECT l.* FROM lecturas l
JOIN sesiones s ON s.id = l.sesion_id
WHERE s.paciente_id = 123 AND s.estado = 'ACTIVA'
ORDER BY l.timestamp_dispositivo, l.id;
```

## Reutilización de MQTT

El POST ya crea una sesión; para utilizarla no hace falta el seeder ni `--simulador`:

```text
docker compose exec backend php artisan mqtt:listen
```

El paquete debe traer `pacienteId` igual a `numero_cama` y el mismo `modo` de la sesión. El listener entrega el paquete a DataPacketService, que guarda lectura y alertas; el GET de detalle permite ver la lectura. Si el simulador emite `cama-01`, una cama de prueba `api-...` no recibirá esas lecturas. Registrar la cama real libre con el modo que envía el simulador; no cambiar el simulador ni sobrescribir registros existentes para forzar la prueba. El modo `--simulador` conserva su comportamiento anterior y puede reemplazar sesiones al cambiar el modo; no es necesario para una sesión ya registrada por la API.

## Pruebas automatizadas aisladas

Requieren PHP 8.4 con `pdo_pgsql`, las dependencias de Composer instaladas en backend y Docker. Usan un PostgreSQL nuevo en **55432**, no `dinostar_rex` ni su volumen. Si ese puerto está ocupado, resolverlo antes de seguir; no cambiar las pruebas para apuntar a la base de trabajo.

Desde la raíz (comando igual en Windows/macOS/Linux):

```text
docker run -d --name dinostar-pacientes68-test-db -p 127.0.0.1:55432:5432 -e POSTGRES_DB=dinostar_issue22_test -e POSTGRES_USER=postgres -e POSTGRES_PASSWORD=issue22-test-only --health-cmd "pg_isready -U postgres -d dinostar_issue22_test" --health-interval 2s --health-timeout 3s --health-retries 15 postgres:15
docker inspect --format '{{.State.Health.Status}}' dinostar-pacientes68-test-db
```

Esperar `healthy`. Si ya existe este contenedor de pruebas, usar `docker start dinostar-pacientes68-test-db` en lugar de crearlo nuevamente.

Windows PowerShell:

```powershell
Set-Location backend
$env:DINOSTAR_MIGRATION_TEST = '1'
php vendor/phpunit/phpunit/phpunit --filter=PacienteApiTest
php vendor/phpunit/phpunit/phpunit
Remove-Item Env:DINOSTAR_MIGRATION_TEST
Set-Location ..
```

macOS/Linux:

```bash
cd backend
DINOSTAR_MIGRATION_TEST=1 php vendor/phpunit/phpunit/phpunit --filter=PacienteApiTest
DINOSTAR_MIGRATION_TEST=1 php vendor/phpunit/phpunit/phpunit
cd ..
```

Sin `DINOSTAR_MIGRATION_TEST=1` estas pruebas se omiten por protección: un resultado con pruebas omitidas no demuestra el funcionamiento. Las pruebas crean sus registros dentro de transacciones y los revierten. Se puede detener y eliminar **solo este contenedor desechable** al terminar:

```text
docker stop dinostar-pacientes68-test-db
docker rm -v dinostar-pacientes68-test-db
```

## Historial de lecturas por sesión — tarea #76

`GET /api/sesiones/{id}/lecturas` consulta una sesión por su **ID de sesión**,
no por ID de paciente ni número de cama. Funciona con sesiones `ACTIVA`,
`PAUSADA` y `FINALIZADA`, incluso cuando el paciente está inactivo.
Devuelve todas sus lecturas, sin paginación, ordenadas por
`timestamp_dispositivo` ascendente y, en caso de empate, por `id` ascendente.

Respuesta **200** (ejemplo):

```json
{
  "data": [
    {
      "gotas_por_min": 32.5,
      "vol_restante": "450.00",
      "tiempo_restante_min": 830,
      "timestamp_dispositivo": "2026-10-20T10:00:00.123000Z"
    },
    {
      "gotas_por_min": 19.9,
      "vol_restante": "45.20",
      "tiempo_restante_min": 136,
      "timestamp_dispositivo": "2026-10-20T10:01:00.123000Z"
    }
  ]
}
```

`gotas_por_min` es numérico, `vol_restante` es texto decimal con dos posiciones
(igual que en el detalle de pacientes), `tiempo_restante_min` es entero y
`timestamp_dispositivo` es ISO 8601 en UTC, con microsegundos.
Una sesión existente sin lecturas responde `200` con `{"data": []}`.
Una sesión inexistente responde `404` con `{"message": "Sesión no encontrada."}`.
Los IDs con formato inválido también responden `404`. Se admiten IDs negativos
para compatibilidad con los datos de demostración existentes.

### Comprobación manual en Windows o macOS

1. Con el backend y PostgreSQL habituales ya iniciados, importa
   `backend/sesiones.postman_collection.json` en Postman. La colección solo hace
   consultas: no crea pacientes, no ejecuta seeders y no publica MQTT.
2. Configura `base_url` (normalmente `http://localhost:8000`) y `sesion_id`.
   Puedes obtenerlo de `data.sesion.id` en `GET /api/pacientes/{paciente_id}` o
   mediante la consulta SQL siguiente. No reutilices IDs de otra computadora.
3. Ejecuta las tres solicitudes en orden. Deben responder `200`, `404`, `404`,
   con ocho comprobaciones aprobadas en total. El caso inexistente usa un ID
   de ejemplo; confirma que no exista en tu base.
4. Para comprobar datos reales, selecciona una sesión que tenga lecturas y
   compara cada elemento con la segunda consulta SQL. Para el caso vacío,
   repite la primera solicitud con una sesión cuyo conteo sea cero.
5. Prueba también una sesión finalizada o pausada, si existe. No hace falta
   modificar sesiones existentes ni volver a ejecutar el seeder.

```sql
SELECT s.id AS sesion_id, s.paciente_id, s.estado, COUNT(l.id) AS lecturas
FROM public.sesiones s
LEFT JOIN public.lecturas l ON l.sesion_id = s.id
GROUP BY s.id, s.paciente_id, s.estado
ORDER BY s.id;

-- Sustituye 6 por el sesion_id obtenido en TU base.
SELECT gotas_por_min, vol_restante, tiempo_restante_min, timestamp_dispositivo
FROM public.lecturas
WHERE sesion_id = 6
ORDER BY timestamp_dispositivo, id;
```

Si publicas nuevos paquetes por el flujo MQTT existente, vuelve a ejecutar la
consulta: las lecturas deben aparecer sin crear otra sesión. El endpoint no
modifica pacientes, sesiones, lecturas ni alertas.

### Pruebas automatizadas de #76

`tests/Feature/SesionLecturaApiTest.php` verifica aislamiento por sesión, orden
cronológico con microsegundos y zonas horarias, desempate estable, estados de
sesión, paciente inactivo, lista vacía, IDs negativos, errores 404 e integración
con `DataPacketService` sin seeder. Se ejecuta en la base aislada descrita arriba:

```powershell
# Desde backend, con la base de pruebas aislada en el puerto 55432.
$env:DINOSTAR_MIGRATION_TEST = '1'
php vendor/phpunit/phpunit/phpunit --filter=SesionLecturaApiTest --do-not-cache-result
php vendor/phpunit/phpunit/phpunit --do-not-cache-result
Remove-Item Env:DINOSTAR_MIGRATION_TEST
```

Las consultas mantienen el esquema de acceso de la API existente; esta tarea
no incorpora autenticación ni cambia los permisos del proyecto.
