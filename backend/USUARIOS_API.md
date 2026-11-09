# API de usuarios — B2 / #112

Contrato para el panel de usuarios. Modelo `User`, tabla `usuarios`. Esta entrega agrega listado, alta, edición y baja lógica; no modifica el frontend ni agrega migraciones.

**Regla temporal acordada:** crear `ADMIN` o promover una enfermera a `ADMIN` por API responde **403**. Los administradores se crean por seeder/BD mientras se integra autenticación (K3). Estas rutas todavía no incorporan autenticación ni autorización por actor: están destinadas al entorno local del proyecto. La restricción de rol no sustituye el control de acceso pendiente.

## Arranque del proyecto local

Desde la raíz del repositorio, en PowerShell, bash o zsh:

```sh
docker compose up -d --build
docker compose ps
docker compose exec -T backend php artisan migrate:status
```

Si hay migraciones pendientes, aplicar las del proyecto con `docker compose exec -T backend php artisan migrate --no-interaction`. No se necesita ejecutar un seeder para crear enfermeras por esta API. No usar `migrate:fresh`, `db:wipe` ni eliminar volúmenes para estas pruebas.

Base URL: `http://localhost:8000`. Enviar `Accept: application/json`; para POST/PATCH, además `Content-Type: application/json` y Body → raw → JSON.

## Endpoints

| Método y ruta | Resultado |
| --- | --- |
| `GET /api/usuarios` | 200, `{"data": [...]}`; incluye activos e inactivos si no se filtra. |
| `POST /api/usuarios` | 201, `{"data": {...}}`. |
| `PATCH /api/usuarios/{id}` | 200, `{"data": {...}}`. |
| `DELETE /api/usuarios/{id}` | 204, sin cuerpo; cambia `activo` a `false`. |

Los IDs son los devueltos por cada base local; pueden diferir entre Windows y Mac. También se admiten IDs negativos existentes. No se agrega `GET /api/usuarios/{id}` en esta tarea.

### Listado y filtros

Ejemplo: `GET /api/usuarios?rol=ENFERMERA&activo=true&nombre=Ana`.

- `rol`: `ADMIN` o `ENFERMERA`, coincidencia exacta.
- `activo`: `true`, `false`, `1` o `0` en la URL. Omitirlo devuelve ambos estados.
- `nombre`: búsqueda parcial sin distinguir mayúsculas, máximo 150 caracteres. `%` y `_` se buscan literalmente.
- Los filtros se combinan; los vacíos se ignoran. Valores inválidos responden 422. Sin coincidencias: `{"data": []}`.
- Orden por `nombre` e `id`; sin paginación en esta entrega.

### Crear una enfermera

```http
POST /api/usuarios
```

```json
{
  "nombre": "Enfermera QA B2",
  "email": "enfermera.qa.b2@example.test",
  "password": "Prueba-B2-123",
  "rol": "ENFERMERA",
  "turno": "MATUTINO"
}
```

Ejemplo de respuesta 201 (ID y fechas ilustrativos):

```json
{
  "data": {
    "id": 42,
    "nombre": "Enfermera QA B2",
    "email": "enfermera.qa.b2@example.test",
    "rol": "ENFERMERA",
    "turno": "MATUTINO",
    "activo": true,
    "created_at": "2026-11-07T15:00:00.000000Z",
    "updated_at": "2026-11-07T15:00:00.000000Z"
  }
}
```

Reglas de campos:

| Campo | Regla |
| --- | --- |
| `nombre` | Obligatorio, texto no vacío, máximo 150 caracteres. |
| `email` | Obligatorio, formato email, máximo 255 caracteres; se recorta y convierte a minúsculas. Único incluso entre usuarios inactivos y sin distinguir mayúsculas. |
| `password` | Obligatoria en POST; mínimo 8 caracteres, máximo 72 bytes por bcrypt. Se almacena como hash y nunca se devuelve. |
| `rol` | Obligatorio: `ENFERMERA` en el alta por API durante esta etapa. |
| `turno` | Obligatorio para enfermeras: `MATUTINO`, `VESPERTINO` o `NOCTURNO`. Un ADMIN existente debe tener `null`. |
| `activo` | Opcional en POST, por defecto `true`; si se envía, usar booleano JSON `true`/`false`, sin comillas. |

El recurso nunca incluye `password` ni `remember_token`. La contraseña se verifica con el proveedor de autenticación existente; el inicio de sesión HTTP corresponde a K3 y no se declara implementado por B2.

### Editar parcialmente

`PATCH /api/usuarios/42` con, por ejemplo:

```json
{
  "nombre": "Enfermera QA B2 editada",
  "turno": "VESPERTINO"
}
```

Solo se modifican campos enviados: `nombre`, `email`, `password`, `rol`, `turno`, `activo`. La contraseña omitida se conserva; enviarla vacía o nula responde 422. Un cuerpo sin campos editables responde 422. Las claves adicionales se ignoran.

Se puede reactivar un usuario con `{"activo": true}`. Promover a `ADMIN` responde 403. Cambiar un ADMIN a ENFERMERA requiere un turno válido y otro administrador activo. Editar un email a su mismo valor es válido.

### Baja lógica y último administrador

`DELETE /api/usuarios/42` no necesita body. Devuelve 204; repetir la baja sobre el mismo usuario existente también devuelve 204.

- Se conserva el usuario y sus relaciones: sesiones, lecturas, alertas y demás historial no se borran ni se reasignan.
- No se finalizan sesiones existentes al dar de baja a una enfermera. El registro de nuevos pacientes exige una enfermera activa.
- Desactivar o cambiar el rol del **último ADMIN activo** responde **409**, tanto por PATCH como DELETE, sin modificarlo.
- Las ediciones y bajas usan una transacción y un bloqueo común en PostgreSQL para serializar la comprobación del último administrador, incluso entre IDs distintos. Las escrituras directas por SQL quedan fuera de esta protección de la API.

## Errores

| Código | Caso |
| --- | --- |
| 403 | Crear ADMIN o promover a ADMIN durante la restricción temporal. |
| 404 | Usuario inexistente o ID con formato inválido. |
| 409 | Desactivar/degradar al último administrador activo. |
| 422 | Email duplicado, campos inválidos, turno incompatible o PATCH vacío. |

Las validaciones 422 devuelven `message` y `errors` por campo. Los demás errores anteriores devuelven `message`. La UI debe manejar los códigos y campos, sin depender del idioma del texto.

## Comprobación en el contenedor del proyecto con Postman

Importar **`backend/usuarios.postman_collection.json`**. Usa `base_url=http://localhost:8000`. Ejecutar **01 a 15 en orden**, una iteración, deteniéndose si una comprobación falla. La petición 01 guarda el ID de una enfermera propia con email único; la última la deja inactiva.

| Peticiones | Qué comprueban |
| --- | --- |
| 01–02 | Alta 201, ausencia de contraseña en la respuesta y filtros combinados. |
| 03–06 | Email duplicado, contraseña corta, turno faltante (422) y ADMIN bloqueado (403). |
| 07–09 | Edición 200 y rechazo de promoción sin alterar el rol/turno. |
| 10–13 | Baja 204, ausencia en activos, permanencia en inactivos y email reservado. |
| 14–15 | Reactivación y baja final del usuario de prueba. |

Si está instalado Postman CLI, desde la raíz (Windows/macOS/Linux):

```sh
postman collection run backend/usuarios.postman_collection.json --bail failure --no-report-events
```

En pgAdmin, conectado a la misma base que el backend, sustituir `42` por el ID devuelto por 01:

```sql
SELECT id, nombre, email, rol, turno, activo,
       (password LIKE '$2%' OR password LIKE '$argon2%') AS password_con_hash
FROM usuarios
WHERE id = 42;
```

Al terminar: el registro sigue existiendo, `rol = 'ENFERMERA'`, `turno = 'VESPERTINO'`, `activo = false` y `password_con_hash = true`. Esta consulta solo comprueba el formato; PHPUnit además verifica la contraseña con `Hash::check`.

La protección del último administrador y la conservación del historial se prueban con datos aislados en PHPUnit, sin desactivar administradores del proyecto manualmente.

## PHPUnit con PostgreSQL desechable

Requiere Docker y el contenedor `dinostar-backend` creado por el arranque anterior. Ejecutar desde la raíz. Se crea un PostgreSQL independiente, sin publicar puertos; cada caso de `UsuarioApiTest` crea y elimina su propia base. Las credenciales siguientes son exclusivamente de prueba.

Estos dos comandos son iguales en Windows y Mac/Linux. Ejecutar el segundo hasta que indique `accepting connections`:

```sh
docker run -d --rm --name dinostar-b2-check-db --tmpfs /var/lib/postgresql/data -e POSTGRES_DB=dinostar_issue22_test -e POSTGRES_USER=postgres -e POSTGRES_PASSWORD=issue22-test-only postgres:15 -p 55432
docker exec dinostar-b2-check-db pg_isready -U postgres -p 55432 -d dinostar_issue22_test
```

**Windows / PowerShell** (copiar como bloque conservando saltos de línea):

```powershell
$imagenB2 = docker inspect --format '{{.Image}}' dinostar-backend
if ($LASTEXITCODE -ne 0) { throw 'No se encontró dinostar-backend.' }
$rutaB2 = (Get-Location).Path
docker run --rm --network container:dinostar-b2-check-db --volumes-from dinostar-backend --mount "type=bind,source=$rutaB2/database,target=/var/www/database,readonly" --entrypoint php -w /var/www/html -e DINOSTAR_MIGRATION_TEST=1 -e APP_ENV=testing -e APP_KEY=base64:MDEyMzQ1Njc4OWFiY2RlZjAxMjM0NTY3ODlhYmNkZWY= -e BROADCAST_CONNECTION=null -e CACHE_STORE=array "$imagenB2" vendor/bin/phpunit --do-not-cache-result --colors=never --testdox --filter UsuarioApiTest
$resultadoB2 = $LASTEXITCODE
Write-Output "Código de PHPUnit: $resultadoB2"
```

**macOS/Linux / bash o zsh**:

```sh
imagen_b2=$(docker inspect --format '{{.Image}}' dinostar-backend)
docker run --rm --network container:dinostar-b2-check-db --volumes-from dinostar-backend --mount "type=bind,source=$(pwd)/database,target=/var/www/database,readonly" --entrypoint php -w /var/www/html -e DINOSTAR_MIGRATION_TEST=1 -e APP_ENV=testing -e APP_KEY=base64:MDEyMzQ1Njc4OWFiY2RlZjAxMjM0NTY3ODlhYmNkZWY= -e BROADCAST_CONNECTION=null -e CACHE_STORE=array "$imagen_b2" vendor/bin/phpunit --do-not-cache-result --colors=never --testdox --filter UsuarioApiTest
resultado_b2=$?
echo "Código de PHPUnit: $resultado_b2"
```

Resultado esperado: `OK (38 tests, 241 assertions)` y código `0`. El filtro forma parte del comando: la suite completa tiene otros requisitos y no debe dirigirse a la base habitual del proyecto.

Al finalizar, en ambos sistemas:

```sh
docker stop dinostar-b2-check-db
docker compose exec -T backend php vendor/bin/pint --test routes/api.php app/Http/Controllers/UsuarioController.php app/Http/Requests/ListUsuarioRequest.php app/Http/Requests/StoreUsuarioRequest.php app/Http/Requests/UpdateUsuarioRequest.php app/Http/Resources/UsuarioResource.php app/Services/GestionUsuarioService.php tests/Feature/UsuarioApiTest.php
```

## Alcance de la evidencia de esta entrega

- Pruebas nuevas: **38 tests / 241 assertions**, sin errores ni fallos.
- Colección ejecutada contra un backend HTTP con PostgreSQL aislado: **15 peticiones / 29 comprobaciones**, sin fallos.
- Dos bajas simultáneas de los dos administradores de prueba respondieron **204 y 409**; quedó **un administrador activo**.
- Pint pasó para los archivos PHP de esta entrega.
- Comparación aislada con `Develop` **c1c8448**: base **131 tests / 983 assertions**, rama B2 **169 tests / 1224 assertions**. Ambas presentan **8 errores y 17 fallos**, en los mismos 25 casos preexistentes; no se afirma que la suite completa esté limpia.
- La colección comprueba HTTP contra el backend al que apunte `base_url`. Para validar otro equipo, ejecutar allí contra su propio contenedor y usar sus IDs.

Este archivo es el entregable nuevo **USUARIOS_API.md** para compartir en **Recursos para código**; mantener sincronizada la copia compartida con la versión incluida en el PR.
