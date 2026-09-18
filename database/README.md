# Inicializacion PostgreSQL - tarea 18

`init.sql` implementa el esquema recibido: usuarios, pacientes, asignaciones,
sesiones, lecturas, alertas, atenciones_alerta y logs_auditoria. Incluye nueve
claves foraneas, restricciones e indices. Falta contrastarlo con el diagrama ER
aprobado: ejecutar el SQL correctamente no demuestra por si solo esa correspondencia.

Desde la raiz del repositorio:

```text
docker compose up -d --build --wait
docker compose exec database psql -U dinostar -d dinostar_rex -c "\dt"
```

Compose usa PostgreSQL 15, crea `dinostar_rex` con `POSTGRES_DB` y monta el SQL
en `/docker-entrypoint-initdb.d/init.sql` en modo lectura. El archivo debe existir;
Compose no creara una carpeta vacia si falta. PostgreSQL ejecuta el script solo
al inicializar un volumen de datos vacio. La transaccion crea todas las tablas
o revierte el esquema si falla alguna instruccion.

Desde Laravel la conexion es `database:5432`. Desde el equipo anfitrion es
`localhost:5433`. Usuario: `dinostar`. Ambos servicios reciben la misma variable
`POSTGRES_PASSWORD` del `.env` raiz, con respaldo publico exclusivo de desarrollo.
Cambiar esa variable no cambia la contrasena de una base ya inicializada.

## Bases existentes

Reiniciar los contenedores no vuelve a ejecutar `init.sql`. No ejecutar
`docker compose down -v` para aplicar cambios: borraria los volumenes del proyecto.
Si el volumen ya existia sin estas tablas, inspeccionar primero su contenido,
respaldarlo y decidir si corresponde aplicar el script a una base vacia o crear
una migracion. No ejecutar este archivo sobre tablas existentes.

La tarea de migraciones Laravel #22 debe coordinarse con este esquema: no crear
dos veces las mismas tablas. El modelo Laravel `User` y sus migraciones iniciales
usan `users`; este SQL define `usuarios`. Su adaptacion corresponde a la integracion
del backend, no se hace automaticamente al montar este archivo.

## Prueba de integridad

Ejecutar solamente sobre una base de pruebas inicializada con este esquema.
La prueba usa datos ficticios y ROLLBACK; no carga seeds del proyecto.

```powershell
Get-Content -Raw database/verify.sql | docker compose exec -T database psql -v ON_ERROR_STOP=1 -U dinostar -d dinostar_rex
```

```bash
docker compose exec -T database psql -v ON_ERROR_STOP=1 -U dinostar -d dinostar_rex < database/verify.sql
```

Comprueba las tablas y FK, inserciones validas, duplicados prohibidos, rangos,
turno obligatorio para enfermeras y proteccion del historial referenciado.
Los permisos por rol, actualizacion de `updated_at`, historial append-only y
sincronizacion entre alerta resuelta y atencion requieren logica/permisos
adicionales; los comentarios SQL distinguen estas limitaciones.
