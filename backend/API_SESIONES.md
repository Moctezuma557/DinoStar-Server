# API de sesiones y lecturas — B3 (#147)

Base local: `http://127.0.0.1:8000`. Contrato revisado en `Develop` `eb59136`,
26/11/2026. Este archivo no existía en esa revisión; se crea para documentar
la paginación y la serie de tiempo de K3 (#129).

## Rutas y acceso

| Método | Ruta | Uso |
| --- | --- | --- |
| POST | `/api/sesiones` | Crear una sesión. |
| PATCH | `/api/sesiones/{id}` | Editar una sesión no finalizada. |
| POST | `/api/sesiones/{id}/finalizar` | Finalizar una sesión y guardar `fin`. |
| GET | `/api/sesiones/{id}/lecturas` | Lecturas paginadas; `serie=1` habilita serie completa. |
| GET | `/api/sesiones/{id}/lecturas/serie` | Alias de la serie completa. |

No existe `GET /api/sesiones` en esta revisión. El historial de reportes de
K2 usa `GET /api/reportes/sesiones`; ver [API_REPORTES.md](API_REPORTES.md).
Los ejemplos y la colección de B3 solo consultan, no crean ni finalizan sesiones.

Enviar `Accept: application/json`. Las rutas GET admiten IDs positivos o
negativos de 1 a 18 dígitos; las rutas PATCH/finalizar solo aceptan IDs positivos.
`{id}` siempre identifica una **sesión**, no un paciente ni un número de cama.
Con `AUTH_PROTEGER_RUTAS=true` requieren un token Bearer de usuario activo
`ADMIN` o `ENFERMERA`; el valor local predeterminado es `false`.

## GET /api/sesiones/{id}/lecturas

Consulta lecturas de esa sesión, también si está `PAUSADA` o `FINALIZADA`, o el
paciente está inactivo. No cambia su estado. El orden es ascendente por
`timestamp_dispositivo` y, en empates, por ID interno de lectura.

| Parámetro | Comportamiento actual |
| --- | --- |
| `limit` | Tamaño de página; alias de `per_page`, valor predeterminado 100. |
| `per_page` | Tiene prioridad si también se envía `limit`. |
| `page` | Número de página, comenzando en 1. Al omitirlo se selecciona la **última** página. |
| `serie` | `1` o `true` devuelve la serie completa, sin paginación. |

El tamaño se convierte a entero y se limita a 1–500. `limit=0` o negativo
se normaliza a 1; `limit=9999` a 500; un valor no numérico se normaliza a 100.
Los valores numéricos fraccionarios se truncan. Estos casos no generan 422.
Si se envía `per_page` vacío o no numérico, se usa 100, aunque `limit` sea válido.

`page=0` y los valores que se convierten a cero seleccionan la última página,
igual que omitir `page`. Un número negativo se normaliza a 1 por el paginador.
Una página posterior a la última devuelve `data: []` con HTTP 200; no se
reemplaza automáticamente por la última.

**Última página no significa las últimas N lecturas.** Con 105 lecturas y
tamaño 100, omitir `page` devuelve la página 2 con las 5 lecturas restantes.
Para recorrer todo el historial, empezar con `page=1` y avanzar hasta
`meta.last_page`, conservando `limit`/`per_page` en cada solicitud. Los enlaces
de paginación actuales solo agregan `page`, no conservan esos parámetros.

Ejemplo ilustrativo con una sesión existente (reemplazar `SESION_ID` por su ID):

```http
GET /api/sesiones/SESION_ID/lecturas?limit=10&page=1
Accept: application/json
```

Ejemplo HTTP 200 para una sesión con una lectura:

```json
{
  "data": [
    {
      "gotas_por_min": 32.5,
      "vol_restante": "450.20",
      "tiempo_restante_min": 830,
      "timestamp_dispositivo": "2026-10-20T10:00:00.123456Z"
    }
  ],
  "links": {
    "first": "http://127.0.0.1:8000/api/sesiones/SESION_ID/lecturas?page=1",
    "last": "http://127.0.0.1:8000/api/sesiones/SESION_ID/lecturas?page=1",
    "prev": null,
    "next": null
  },
  "meta": {
    "current_page": 1,
    "from": 1,
    "last_page": 1,
    "links": [
      { "url": null, "label": "&laquo; Previous", "page": null, "active": false },
      { "url": "http://127.0.0.1:8000/api/sesiones/SESION_ID/lecturas?page=1", "label": "1", "page": 1, "active": true },
      { "url": null, "label": "Next &raquo;", "page": null, "active": false }
    ],
    "path": "http://127.0.0.1:8000/api/sesiones/SESION_ID/lecturas",
    "per_page": 10,
    "to": 1,
    "total": 1
  }
}
```

`gotas_por_min` es un número JSON, `vol_restante` una cadena decimal con dos
decimales y `tiempo_restante_min` un entero. `timestamp_dispositivo` es una
fecha ISO 8601 UTC con microsegundos, no un timestamp Unix en milisegundos.
Los elementos no exponen el ID de lectura ni el ID de sesión. Las etiquetas de
`meta.links` pueden variar según el idioma; no deben usarse como identificadores.

Una sesión existente sin lecturas devuelve HTTP 200, `data: []`,
`meta.total: 0`, `meta.last_page: 1`, `meta.from: null` y `meta.to: null`.
Si no se solicita otra página, `meta.current_page` es 1.

## Serie de tiempo

Estas solicitudes equivalen y devuelven todas las lecturas de la sesión:

```http
GET /api/sesiones/SESION_ID/lecturas?serie=1
GET /api/sesiones/SESION_ID/lecturas/serie
```

HTTP 200:

```json
{
  "data": [
    {
      "timestamp_dispositivo": "2026-10-20T10:00:00.123456Z",
      "gotas_por_min": 32.5,
      "vol_restante": "450.20"
    }
  ]
}
```

El orden y los tipos son los del historial. No incluye `tiempo_restante_min`,
`meta` ni `links`. `limit`, `per_page` y `page` se ignoran en este modo.
Sin lecturas responde exactamente `{"data":[]}`. El alias `/lecturas/serie`
activa el modo incluso si se envía `serie=0`.

## Errores de las consultas

| Caso | HTTP | Cuerpo esperado |
| --- | --- | --- |
| ID de sesión válido que no existe | 404 | `{"message":"Sesión no encontrada."}`. |
| ID `abc` o más de 18 dígitos | 404 | JSON con `message`; la ruta no coincide, no llega a consultar SQL. |
| Token ausente/inválido con protección activa | 401 | JSON, `code: "no_autenticado"`. |
| Usuario inactivo | 401 | JSON, `code: "usuario_inactivo"`. |
| Rol no autorizado con protección activa | 403 | JSON, `code: "rol_no_autorizado"`. |

La sesión inexistente también devuelve 404 en las dos variantes de serie.
El ID inválido no tiene que devolver el mismo mensaje que una sesión inexistente.
Los límites fuera de rango y las páginas vacías son respuestas 200 normalizadas,
no errores de validación 422.

## Compatibilidad y fuentes

El consumidor actual `frontend/src/services/lecturas.js` lee `respuesta.data`
y convierte las cantidades a número. Se conservan los cuatro campos de cada
lectura y el orden. Agregar `links`/`meta` no cambia ese arreglo. B3 no modifica
controladores, recursos, rutas, migraciones, dependencias ni frontend.

Fuentes: `routes/api.php`, `SesionLecturaController`, `LecturaResource`,
`Lectura::casts()` y `tests/Feature/SesionLecturaApiTest.php`. Los feature tests
cubren además estados, aislamiento entre sesiones, empates de microsegundos,
sesiones vacías y paginación con datos controlados.

## Ejecutar la colección Postman

Archivo: [reportes-lecturas.postman_collection.json](reportes-lecturas.postman_collection.json).
Formato Collection v2.1; importar el JSON en Postman y ejecutar **toda la colección
en orden**, una iteración, con **No Environment**. No hace falta editar IDs,
fechas ni sala: se descubren con GET de pacientes y su detalle. La preparación
también recorre el historial de reportes para obtener una referencia paginada
y elegir automáticamente un ID de sesión inexistente.

Requisitos del ambiente local:

- Backend en `http://127.0.0.1:8000`, PostgreSQL con el esquema actual y al menos
  un paciente activo con sesión ACTIVA existente y sala asignada. No es necesario
  crear datos desde esta colección. Puede usarse la demo previamente instalada.
- `AUTH_PROTEGER_RUTAS=false` para la ejecución predeterminada. Si el ambiente
  usa protección, proporcionar `token` mediante un entorno local o `--env-var`.
  El token debe ser válido y pertenecer a un `ADMIN` o `ENFERMERA` activo.
- Mantener los datos estables durante la ejecución: pausar el emisor de
  telemetría y las ediciones desde otras aplicaciones. Las pruebas comparan
  páginas y serie contra las lecturas capturadas en el detalle inicial.

La preparación falla explícitamente si no encuentra paciente/sesión elegible y
detiene el recorrido; no omite las comprobaciones ni crea registros para pasar.
Las sesiones seleccionadas pueden tener cero lecturas: la paginación y las
series vacías también se comprueban. La ausencia de lecturas no sustituye las
pruebas de offset con datos de `SesionLecturaApiTest`.

La preparación del historial repite su solicitud por cada página de 100 sesiones.
El Runner debe permitir scripts y cambios de orden con `setNextRequest`.
Para comprobar páginas y el ID ausente se requiere ejecutar la colección completa;
enviar una solicitud aislada con **Send** no realiza esa preparación.

La colección contiene únicamente solicitudes GET. Los GET de pacientes pueden
agregar entradas a `logs_auditoria` por el comportamiento existente del servidor;
no cambia pacientes, sesiones ni lecturas. No ejecuta SQL, migraciones, seeders,
altas, bajas ni limpieza de datos.

Ejecutar desde la raíz del repositorio.

Si los servicios están detenidos, levantar backend y su dependencia PostgreSQL
conservando los volúmenes existentes (mismo comando en Windows y macOS/Linux):

```bash
docker compose up -d --build --wait backend
```

Después, en Windows PowerShell:

```powershell
postman collection run "backend/reportes-lecturas.postman_collection.json"; if ($LASTEXITCODE -ne 0) { throw 'Falló la verificación de contratos B3.' }
```

macOS/Linux:

```bash
postman collection run backend/reportes-lecturas.postman_collection.json
```

Exigir código de salida 0 y ninguna aserción fallida. No usar opciones para
suprimir el código de error. Para otra URL o autenticación, sin editar el JSON:

```bash
postman collection run backend/reportes-lecturas.postman_collection.json --env-var base_url=http://127.0.0.1:8000 --env-var token=TOKEN_LOCAL
```

No publicar el token ni el historial de la terminal que lo contenga. Los comandos
y sus opciones corresponden a [Postman CLI](https://learning.postman.com/docs/postman-cli/postman-cli-collections).
La corrida de Windows compartida el 26/11/2026 completó 65 solicitudes y
257 aserciones sin fallos; la repetición en macOS todavía no está registrada.
La evidencia, la verificación de rutas y el alcance de las pruebas se describen
en [API_REPORTES.md](API_REPORTES.md).

Cobertura de la colección: respuestas de resumen/categorías/historial/auditoría/PDF,
cinco filtros combinados, filtros contradictorios sin coincidencias, validaciones
422, paginación por defecto/explícita de reportes y lecturas, alias/prioridad de
tamaño en lecturas, límites normalizados frente a límites validados, página fuera
de rango, serie por query y ruta, y errores 404. La auditoría contrasta IDs de
alertas distintos con `numero_alertas` y comprueba `null` en atenciones no
resolutivas. No sustituye los feature tests ni prueba todos los roles de autenticación.
