# Título propuesto

feat(#68): registrar y consultar pacientes con su sesión activa

# Descripción para el PR

Permite registrar desde el frontend un paciente y su sesión ACTIVA en una sola operación, consultar los pacientes activos por nombre/enfermera y obtener el detalle con las lecturas de su sesión actual.

## Cambios

- `POST /api/pacientes`: valida los seis campos solicitados y `enfermera_id` (acordado para esta entrega); crea paciente y sesión atómicamente y retorna 201 con JSON y Location.
- Valida que la enfermera exista, tenga rol ENFERMERA y esté activa. Los errores de entrada retornan 422.
- Rechaza con 409 camas reservadas, incluyendo sesiones pausadas. Serializa altas concurrentes de la misma cama mediante bloqueo transaccional en PostgreSQL.
- `GET /api/pacientes`: lista pacientes activos con sesión ACTIVA; permite filtros opcionales `nombre` y `enfermera_id`.
- `GET /api/pacientes/{id}`: devuelve los datos del paciente, la sesión actual con `tipo_solucion` y sus lecturas ordenadas. Retorna 404 si no existe un paciente activo con sesión ACTIVA.
- Registra las tres rutas en `routes/api.php` y agrega recursos JSON con campos explícitos.
- Corrige el arranque de Docker con `artisan serve --no-reload` para conservar las variables de Compose en el servidor HTTP, incluso cuando el `.env` local usa SQLite. Antes, Artisan usaba PostgreSQL pero Postman podía recibir un 500 por consultar SQLite.
- Reutiliza la migración y `$fillable` existentes de `tipo_solucion`. Conserva el simulador, los seeders y DataPacketService.
- Incluye guía de consumo y pruebas Windows/macOS/Linux en `backend/API_PACIENTES.md`, además de colección Postman con ocho solicitudes.

## Decisiones de integración

El número de cama es global para esta API porque los paquetes MQTT no contienen sala. Un paciente activo sin sesión también reserva la cama: no se sobrescriben pacientes demo ni su historial. La API registra nuevos pacientes; la gestión de alta/baja de pacientes existentes queda fuera de estos tres endpoints.

Esta entrega todavía no incorpora autenticación. `enfermera_id` asigna la sesión y no autentica al solicitante; los filtros tampoco sustituyen permisos. Se integra y prueba con datos ficticios en el entorno local.

## Validación

- Regresión completa final: 71 pruebas y 676 comprobaciones aprobadas, sin pruebas omitidas, sobre PostgreSQL aislado (Windows, PHP 8.4).
- Pruebas de la API sobre PostgreSQL aislado: 33 pruebas, 229 comprobaciones aprobadas.
- Alta sin seeder, enfermera inválida/inactiva, validaciones, duplicados, cama pausada, conservación del historial, rollback ante fallo al crear sesión y filtros.
- Detalle: lecturas únicamente de la sesión activa, orden por timestamp, compatibilidad con IDs demo negativos y tipo_solucion antiguo nulo.
- Integración con DataPacketService: lectura y alertas de un paciente creado por API; lote de cuatro camas sin seeders ni reemplazar las sesiones registradas.
- Prueba HTTP real con dos POST simultáneos: respuestas 201 y 409, con exactamente un paciente y una sesión en PostgreSQL.
- HTTP real: listado, filtros y detalle 200; inexistente 404; filtros y POST inválidos 422.
- Regresión del arranque real de Docker con `.env` local y PostgreSQL aislado: alta 201, cama duplicada 409, listado/detalle 200 y entrada inválida 422. Tras reconstruir el backend habitual, el listado devuelve 200 con sus cuatro pacientes existentes.
- Pint y comprobación de formato del diff aprobados.
- Capturas de Postman y ejecución manual en Mac: pendientes de adjuntar; no se presentan como verificadas en este PR.

## Cómo comprobar

Desde la raíz, en Windows, macOS o Linux:

```text
docker compose up -d --build --wait database broker backend
docker compose exec backend php artisan migrate --no-interaction
docker compose exec backend php artisan route:list --path=api
```

1. Consultar una enfermera activa existente (consulta en la guía).
2. Importar `backend/pacientes.postman_collection.json`; establecer `base_url` y `enfermera_id`.
3. Ejecutar las ocho solicitudes en orden: la primera crea un paciente ficticio con una cama nueva; la segunda debe devolver 409.
4. Revisar los filtros, el detalle, los errores 404/422 y los registros en pgAdmin.
5. Para repetir las pruebas automatizadas, seguir la sección de PostgreSQL aislado de `API_PACIENTES.md`. No apuntarlas a la base habitual del proyecto.

Closes #68

---

## Preparación del envío (no forma parte de la descripción)

Rama de trabajo: `feature/endpoints-pacientes-68`, creada desde `89a16a0` para conservar las correcciones anteriores de MQTT y tipo_solucion. Antes de abrir contra Develop, comprobar si esos commits ya fueron integrados. Si no lo fueron, revisar como PR dependiente del trabajo anterior para no mezclar cambios ajenos a #68.

Mensaje de commit sugerido: `feat(#68): registrar y consultar pacientes con sesion activa`.

Adjuntar capturas de Postman y elegir las etiquetas existentes que correspondan a backend/funcionalidad según las convenciones del equipo. Mover la tarea a Review cuando esté lista para revisión. Este archivo prepara el contenido; no crea ni publica un PR.
