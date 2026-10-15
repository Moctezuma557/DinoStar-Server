# API de pacientes — tarea #68

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

### Errores

| HTTP | Causa | Respuesta |
| --- | --- | --- |
| 404 | Paciente inexistente, inactivo o sin sesión activa | `{"message":"Paciente no encontrado o sin sesión activa."}` |
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

## Postman y evidencia para el PR

Importar `backend/pacientes.postman_collection.json`. Configurar variables de colección `base_url` y `enfermera_id`. Ejecutar en orden las ocho solicitudes. La primera genera una cama `api-...` libre, guarda `paciente_id` y deja los datos ficticios en la base; las demás reutilizan esos valores. Cada ejecución completa crea otro paciente de prueba.

Adjuntar capturas reales de POST 201, duplicado 409, listado 200, filtro 200, detalle 200 y errores 404/422. La colección contiene comprobaciones, pero el archivo JSON o los resultados de PHPUnit no reemplazan el criterio de capturas de Postman. No incluir datos personales reales en la evidencia.

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

### Resultado verificado el 14 de octubre de 2026

- Windows, PHP 8.4 y PostgreSQL 15 aislado: **71 pruebas / 676 comprobaciones**, todas aprobadas y sin omisiones.
- De ellas, **33 pruebas / 229 comprobaciones** cubren la API de pacientes, incluyendo cuatro altas seguidas de un lote MQTT mediante DataPacketService sin seeder.
- HTTP real en puerto temporal 18068, cuatro procesos PHP: dos POST simultáneos para la misma cama produjeron **201 y 409**; la base conservó exactamente **1 paciente y 1 sesión**.
- Listado, filtros y detalle por HTTP devolvieron 200; paciente inexistente 404; filtro inválido y POST vacío 422.
- No se usó ni modificó la base habitual del proyecto durante estas pruebas. La ejecución en Mac y las capturas reales de Postman quedan para la validación del equipo.
