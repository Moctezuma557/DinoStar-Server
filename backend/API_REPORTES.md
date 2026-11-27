# API de reportes — B3 (#147)

Base local: `http://127.0.0.1:8000`. Contratos revisados contra `Develop`
`eb59136` el 26/11/2026. Esta documentación no agrega ni modifica endpoints.

## Estado de la integración

| Método | Ruta | Estado en la revisión |
| --- | --- | --- |
| GET | `/api/reportes/resumen` | Registrada (K1). |
| GET | `/api/reportes/alertas-categoria` | Registrada (K1). |
| GET | `/api/reportes/exportar` | Registrada (B2). |
| GET | `/api/reportes/sesiones` | Registrada (K2, #143). |
| GET | `/api/reportes/sesiones/{id}/alertas` | Registrada (K2, #143). |

K2 está integrado y `ReporteController::datos()` está definido. Las respuestas
JSON conservan el envoltorio `data` y los decimales mediante
`JSON_PRESERVE_ZERO_FRACTION`. Fuentes: `routes/api.php`, `ReporteController`,
los requests/recursos de reportes y `ReporteApiTest`.

## Encabezados y acceso

Enviar `Accept: application/json`, incluso al probar errores de exportación.
La descarga correcta devuelve `application/pdf` independientemente de ese
encabezado. No se envía cuerpo en estas solicitudes GET.

Con `AUTH_PROTEGER_RUTAS=false` (valor local predeterminado), no se exige token.
Con `true`, enviar `Authorization: Bearer <token>` de un usuario activo con rol
`ADMIN` o `ENFERMERA`. No guardar tokens reales en la colección ni en Git.
El contrato de autenticación está en [USUARIOS_API.md](USUARIOS_API.md).

## Filtros comunes

Resumen y categorías usan `FiltrosReporteRequest`; exportación e historial lo
heredan mediante `ExportarReporteRequest` y `ListarSesionesReporteRequest`.
**La auditoría por ID no recibe filtros** y devuelve todas las alertas de esa sesión.

| Parámetro opcional | Contrato |
| --- | --- |
| `fecha_inicio` | Fecha válida `YYYY-MM-DD`, día inicial inclusivo. |
| `fecha_fin` | Fecha válida `YYYY-MM-DD`, día final inclusivo; debe ser mayor o igual al inicial cuando ambos se envían. |
| `paciente_id` | Entero entre `-999999999999999999` y `999999999999999999`. Es el ID de tabla del paciente, no la cama ni el ID de sesión. |
| `sala` | Nombre exacto de la sala, texto de hasta 50 caracteres. |
| `enfermera_id` | Entero en el mismo rango que `paciente_id`; enfermera asignada a la sesión. |

Los filtros se combinan con AND. Ausentes o vacíos no restringen resultados.
Se admiten IDs negativos de los datos demo. `enfermera_id` filtra la enfermera
asignada a la sesión, no la autora de una atención. `sala` se compara con la
ubicación actual del paciente. Un ID válido sin coincidencias
produce un resultado vacío, no un error de existencia. Los nombres públicos
son `fecha_inicio` y `fecha_fin`, con guion bajo: `desde`, `hasta`,
`fecha-inicio` y `fecha-fin` no son alias. Los parámetros desconocidos se ignoran.

El rango usa `app.timezone` (`UTC` en esta revisión). Internamente se compara
desde las 00:00 del inicio hasta antes de las 00:00 del día siguiente al final.
Puede enviarse solo un extremo. `page`, `per_page` y `limit` no paginan resumen,
categorías ni PDF; el PDF incluye todas las sesiones coincidentes.

## GET /api/reportes/resumen

Ejemplo con fechas:

```http
GET /api/reportes/resumen?fecha_inicio=2026-11-01&fecha_fin=2026-11-30
Accept: application/json
```

Respuesta HTTP 200 (ejemplo ilustrativo, cantidades según la base):

```json
{
  "data": {
    "total_sesiones": 3,
    "sesiones_completadas": { "cantidad": 2, "porcentaje": 66.67 },
    "sesiones_con_alertas": 2,
    "tiempo_promedio_respuesta": null
  }
}
```

| Campo | Cálculo y tipo JSON |
| --- | --- |
| `total_sesiones` | Entero; sesiones cuyo `inicio` está en el rango, con cualquier estado. |
| `sesiones_completadas.cantidad` | Entero; sesiones del mismo conjunto con estado `FINALIZADA`. |
| `sesiones_completadas.porcentaje` | Número; cantidad / total × 100, redondeado a 2 decimales; cero si el total es cero. |
| `sesiones_con_alertas` | Entero; sesiones distintas con alertas creadas en el rango. Puede incluir sesiones iniciadas antes del rango y superar `total_sesiones`. |
| `tiempo_promedio_respuesta` | Número en segundos, redondeado a 2 decimales, o `null` si no hay atenciones que resolvieron alertas. |

El promedio usa `atenciones_alerta.created_at - alertas.created_at`, únicamente
atenciones con `resolvio=true`. Se filtra por fecha de creación de la alerta;
una atención posterior al final del rango sigue contando si su alerta pertenece
al rango. Una resolución inmediata puede dar `0`: no equivale a `null`.
Sin coincidencias, los conteos y el porcentaje son cero y el promedio es `null`.

## GET /api/reportes/alertas-categoria

Acepta los mismos cinco filtros. Contrato HTTP 200, con tres filas siempre y
en este orden (ejemplo ilustrativo):

```json
{
  "data": [
    { "tipo": "GOTEO_LENTO", "cantidad": 2, "porcentaje": 50.0, "tiempo_promedio_resolucion": 90.0 },
    { "tipo": "FIN_BOLSA", "cantidad": 2, "porcentaje": 50.0, "tiempo_promedio_resolucion": null },
    { "tipo": "GOTEO_RAPIDO", "cantidad": 0, "porcentaje": 0.0, "tiempo_promedio_resolucion": null }
  ]
}
```

`cantidad` es un entero. Los porcentajes usan como denominador **todas** las
alertas del rango, incluida `DESCONECTADO`; por ello las tres filas pueden
sumar menos de 100 %. También puede haber diferencias pequeñas por redondeo.
El promedio por categoría sigue la misma regla y unidad que el promedio del
resumen. Sin coincidencias, se conservan las tres filas con ceros y `null`.

## GET /api/reportes/sesiones

Acepta los cinco filtros comunes y estos parámetros adicionales:

| Parámetro opcional | Validación y valor predeterminado |
| --- | --- |
| `page` | Entero entre 1 y 1 000 000; predeterminado 1. |
| `per_page` | Entero entre 1 y 100; predeterminado 10. |

Ausentes o vacíos usan los valores predeterminados. Los valores inválidos
devuelven 422, sin normalizarlos al mínimo/máximo. `limit` no es alias aquí:
se ignora. Esta paginación es diferente de la de [lecturas](API_SESIONES.md).

```http
GET /api/reportes/sesiones?fecha_inicio=2026-11-01&fecha_fin=2026-11-30&per_page=10&page=1
Accept: application/json
```

Respuesta HTTP 200 paginada. Ejemplo ilustrativo con una sesión:

```json
{
  "data": [
    {
      "id": -13501,
      "paciente": "Paciente de ejemplo",
      "enfermera": "Enfermera de ejemplo",
      "ubicacion": { "sala": "Sala A", "numero_cama": "cama-01" },
      "inicio": "2026-11-20T00:00:00.000000Z",
      "fin": "2026-11-20T00:20:00.000000Z",
      "duracion_segundos": 1200.0,
      "tipo_solucion": "Solución salina 0.9%",
      "vol_total": 500.0,
      "numero_alertas": 2,
      "estado": "FINALIZADA"
    }
  ],
  "links": {
    "first": "http://127.0.0.1:8000/api/reportes/sesiones?page=1",
    "last": "http://127.0.0.1:8000/api/reportes/sesiones?page=1",
    "prev": null,
    "next": null
  },
  "meta": {
    "current_page": 1,
    "from": 1,
    "last_page": 1,
    "links": [
      { "url": null, "label": "&laquo; Previous", "page": null, "active": false },
      { "url": "http://127.0.0.1:8000/api/reportes/sesiones?page=1", "label": "1", "page": 1, "active": true },
      { "url": null, "label": "Next &raquo;", "page": null, "active": false }
    ],
    "path": "http://127.0.0.1:8000/api/reportes/sesiones",
    "per_page": 10,
    "to": 1,
    "total": 1
  }
}
```

Los enlaces anteriores ilustran una solicitud sin query. Al enviar filtros o
`per_page`, los enlaces reales los conservan mediante `withQueryString()`.
Las etiquetas de `meta.links` pueden cambiar con el idioma.

| Campo de cada fila | Tipo y significado |
| --- | --- |
| `id` | Entero; ID de sesión para consultar su auditoría. |
| `paciente`, `enfermera` | Nombres actuales en sus tablas. No se exponen `paciente_id` ni `enfermera_id` en esta respuesta. |
| `ubicacion` | Objeto con `sala` y `numero_cama` del paciente. |
| `inicio`, `fin` | Fechas ISO 8601 UTC con microsegundos; `fin` puede ser `null`. |
| `duracion_segundos` | Número: `fin - inicio` en segundos; `null` si no hay `fin`. |
| `tipo_solucion` | Texto o `null`. |
| `vol_total` | Número en mililitros o `null`; no es la cadena decimal de `vol_restante` en lecturas. |
| `numero_alertas` | Entero; total de alertas de la sesión, incluyendo las que están fuera del rango consultado. |
| `estado` | `ACTIVA`, `PAUSADA` o `FINALIZADA`. |

El rango filtra por `sesiones.inicio`. Devuelve todos los estados y también
sesiones de pacientes inactivos. Orden: inicio descendente y luego ID descendente.
Sin coincidencias responde 200 con `data: []`, `meta.total: 0`, `last_page: 1`
y `from`/`to` nulos. Una página válida posterior a la última también devuelve
`data: []`, pero conserva el total real y la página solicitada.

## GET /api/reportes/sesiones/{id}/alertas

`{id}` es el ID de sesión recibido en el historial, con signo opcional y entre
1 y 18 dígitos. Esta consulta no pagina ni aplica los filtros del listado.
Incluso si la tabla se filtró por fechas, la auditoría incluye todas las alertas
de la sesión. Los parámetros de query se ignoran, no generan validaciones 422.

```http
GET /api/reportes/sesiones/SESION_ID/alertas
Accept: application/json
```

Ejemplo ilustrativo HTTP 200:

```json
{
  "data": [
    {
      "id": 101,
      "tipo": "GOTEO_LENTO",
      "hora": "2026-11-20T00:00:10.000000Z",
      "descripcion": "Goteo por debajo del rango",
      "atendida_por": "Enfermera de ejemplo",
      "resolvio": false,
      "tiempo_respuesta": null
    },
    {
      "id": 101,
      "tipo": "GOTEO_LENTO",
      "hora": "2026-11-20T00:00:10.000000Z",
      "descripcion": "Goteo por debajo del rango",
      "atendida_por": "Enfermera de ejemplo",
      "resolvio": true,
      "tiempo_respuesta": 90.0
    }
  ]
}
```

`id` identifica la **alerta** y puede repetirse: hay una fila por atención y una
fila con atención nula por cada alerta sin atender. Para contrastar con
`numero_alertas`, contar IDs distintos, no filas. La auditoría incluye también
tipos como `DESCONECTADO`, aunque no aparezcan entre las tres categorías del resumen.

`hora` es la creación de la alerta en ISO 8601 UTC con microsegundos;
`descripcion` proviene de su mensaje. `atendida_por` es el nombre de la enfermera
que atendió, o `null` sin atención. `resolvio` es booleano, o `null` sin atención.
`tiempo_respuesta` es un número en segundos, calculado con
`atenciones_alerta.created_at - alertas.created_at` solo si `resolvio=true`;
en otro caso es `null`. La auditoría conserva la precisión del cálculo, mientras
los promedios de resumen/categorías se redondean a dos decimales.

Orden ascendente por fecha/ID de alerta y después por fecha/ID de atención.
No contiene `links` ni `meta`. Una sesión existente sin alertas devuelve
`{"data":[]}` con 200. Una sesión inexistente devuelve 404 JSON con
`{"message":"Sesión no encontrada."}`. Un ID como `abc` o de más de 18 dígitos
devuelve 404 de ruta, sin exigir el mismo texto del mensaje.

## GET /api/reportes/exportar

Acepta los mismos filtros y devuelve HTTP 200 con:

```http
Content-Type: application/pdf
Content-Disposition: attachment; filename=dinostar-reporte-AAAAMMDD-HHMMSS.pdf
Cache-Control: private, no-store
X-Content-Type-Options: nosniff
```

El formato exacto de las comillas de `filename` puede variar. El archivo real
comienza con `%PDF-`; no es HTML renombrado. Incluye los cuatro KPIs, las tres
categorías y las sesiones auditadas: paciente/ID, enfermera, sala/cama,
inicio/fin, duración, solución/volumen, alertas y estado. Las sesiones se ordenan
por inicio descendente y luego por ID descendente. Incluye historial de
sesiones activas, pausadas y finalizadas, incluso de pacientes inactivos.

Los promedios se muestran en segundos y la duración de sesiones en minutos.
`—` indica que no hay promedio o duración disponible. Sin sesiones coincidentes
genera igualmente un PDF con el mensaje
`No se encontraron sesiones con los filtros seleccionados.`
No persiste copias del PDF ni modifica los datos del reporte.

En Windows, desde la raíz del repositorio:

```powershell
curl.exe --fail-with-body --get "http://127.0.0.1:8000/api/reportes/exportar" --data-urlencode "fecha_inicio=2026-11-01" --data-urlencode "fecha_fin=2026-11-30" --data-urlencode "sala=Sala A" -H "Accept: application/json" --output "$env:TEMP/dinostar-reporte.pdf"
```

En macOS/Linux, usar el mismo comando con `curl` y un destino como
`--output /tmp/dinostar-reporte.pdf`. En Postman: **Send**, revisar **Preview**
y usar **Save Response** para guardar y abrir el archivo.

## Errores esperados

| Solicitud o condición | HTTP | Comprobación |
| --- | --- | --- |
| `fecha_inicio=2026-02-30` | 422 | `errors.fecha_inicio` es un arreglo de mensajes. |
| `fecha_inicio=2026-11-30&fecha_fin=2026-11-01` | 422 | `errors.fecha_fin`. |
| `paciente_id=abc` o entero fuera del rango | 422 | `errors.paciente_id`. |
| `enfermera_id=1.5` | 422 | `errors.enfermera_id`. |
| `sala` de más de 50 caracteres o de tipo arreglo | 422 | `errors.sala`. |
| Historial con `per_page=0`, `101` o `diez` | 422 | `errors.per_page`. |
| Historial con `page=0`, negativo, no entero o mayor a 1 000 000 | 422 | `errors.page`. |
| Auditoría de sesión inexistente o ID inválido | 404 | JSON con `message`; ver la distinción anterior. |
| Sin token válido con protección activada | 401 | JSON, `code: "no_autenticado"`. |
| Cuenta desactivada con token válido | 401 | JSON, `code: "usuario_inactivo"`. |
| Rol no autorizado con protección activada | 403 | JSON, `code: "rol_no_autorizado"`. |
| Método POST en una ruta solo GET | 405 | JSON. |

Las validaciones de filtros aplican a resumen, categorías, historial y exportación.
Los errores de filtros también se devuelven como JSON al exportar; no guardar
esa respuesta como un PDF. Ejemplo 422 (el texto puede depender del idioma):

```json
{
  "message": "The paciente id field must be an integer.",
  "errors": { "paciente_id": ["The paciente id field must be an integer."] }
}
```

## Colección Postman y verificación

Importar [reportes-lecturas.postman_collection.json](reportes-lecturas.postman_collection.json)
y ejecutar la colección completa en orden. Los requisitos, comandos para Windows
y macOS y límites de las pruebas están en [API_SESIONES.md](API_SESIONES.md#ejecutar-la-colección-postman).
Los casos de error comprueban los nombres de campo, no mensajes traducibles.
La colección incluye historial y auditoría de K2 y contrasta páginas con una
referencia obtenida por la propia API. El PDF vacío debe revisarse además
visualmente para comprobar el mensaje; sus scripts verifican firma y cabeceras.

### Rutas verificadas

Desde la raíz, con el backend levantado:

```bash
docker compose exec -T backend php artisan route:list --path=api
```

La revisión de `eb59136` obtuvo 28 rutas API y confirmó las cinco rutas de
reportes registradas en la tabla inicial. Se ejecutó Artisan en un contenedor
temporal sin red, con el código y las dependencias en solo lectura, porque el
servicio local estaba detenido. `route:list` verifica el registro de rutas;
no demuestra que sus respuestas sean correctas.

### Formato de la colección verificado

Postman CLI convirtió el JSON v2.1 a una copia temporal v3 y ejecutó su linter:
65 archivos revisados, cero errores y cero advertencias. El JSON v2.1 permanece
como entregable importable del repositorio; la copia temporal no forma parte del PR.
Se comprobó también la sintaxis de los 67 scripts, las rutas GET utilizadas,
los enlaces de documentación y los ejemplos JSON.

La colección define 65 solicitudes y 257 aserciones. El paso de preparación del
historial se repite cuando hay más de 100 sesiones, por lo que los totales de
ejecución pueden aumentar. Convertir y analizar la colección no ejecuta sus
solicitudes ni demuestra que las 257 aserciones pasen contra el servidor.

### Validación HTTP en Windows

La ejecución local compartida por el usuario el 26/11/2026 terminó con el backend
y PostgreSQL en estado `Healthy`: una iteración, 65 solicitudes y 257 aserciones,
con cero fallos en solicitudes, scripts y aserciones. Duración: 23,4 segundos.
La colección descubrió automáticamente los IDs y filtros, sin editar el JSON.

Pasaron las respuestas 200 de resumen, categorías, historial, auditoría, PDF y
lecturas; los casos sin coincidencias; y los errores 422/404 esperados. Las páginas
y series coincidieron con las referencias capturadas por la propia colección.
El aviso de credenciales de Postman se refiere a publicar los resultados en su
nube; la ejecución local completó las pruebas sin iniciar sesión en Postman.

Esta evidencia valida los casos ejecutados contra esa base local; no sustituye
los feature tests, la prueba visual de la interfaz ni la revisión visual del
contenido de los PDF. La repetición en macOS aún no se ha registrado para B3.

### Entrega y revisión

Las dependencias K1, K2 y K3 ya están integradas. El frontend, sus adaptadores y
las respuestas de la API no se modifican en B3. PR hacia `Develop`, con
`Closes #147`; título propuesto:
`docs(#147): documentar contratos de reportes y lecturas`.
Tras adjuntar los resultados de las pruebas al PR, mover la tarea a
**En revisión / Review** para solicitar revisión.
