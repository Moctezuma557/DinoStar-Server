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

## Migraciones Laravel (tarea #22)

Actualizar la rama y reconstruir el backend antes de ejecutar Artisan: el codigo
PHP se copia a la imagen durante el build, no se monta desde el anfitrion.

```text
docker compose up -d --build --wait
docker compose exec backend php artisan migrate --no-interaction
docker compose exec backend php artisan migrate:status
```

Hay una migracion por cada una de las ocho tablas de dominio. En una base vacia
las crean; si `init.sql` ya las creo, comparan columnas, tipos, nulabilidad,
valores predeterminados, identidad, restricciones e indices antes de adoptarlas.
Una estructura incompatible detiene la migracion y requiere una correccion
explicita; no se oculta con un simple `hasTable`. Cada migracion usa transacciones.
Las migraciones anteriores que ya terminaron pueden quedar registradas si otra
posterior falla. Corregir el problema y volver a ejecutar `migrate`.

La adopcion crea `dinostar_schema_adoptions` para proteger las tablas preexistentes
de un rollback. No borrar esa tabla de control. Laravel ademas crea `migrations`
y las tablas tecnicas de cache y colas; ver mas de ocho tablas es normal.
Ejecutar `migrate` una segunda vez debe informar que no hay migraciones pendientes.
El SQL inicial sigue siendo compatible con #18; para cambios posteriores crear
una NUEVA migracion, sin editar las ocho migraciones iniciales ni sus nombres.

`User` ahora utiliza `usuarios` y el campo `nombre`. Factory y seeder usan el
mismo esquema; `migrate` no ejecuta seeds ni crea cuentas. No se implementan
verificacion de email ni remember-token, ausentes del esquema #18.
El rol no es asignable masivamente desde peticiones del usuario.
La migracion historica `create_users_table` se conserva como operacion vacia:
no crea `users`, `sessions` ni `password_reset_tokens` en instalaciones nuevas,
y tampoco borra tablas antiguas. Si existen cuentas en `users`, se conservan
pero NO se transfieren automaticamente a `usuarios`; planificar esa transferencia
antes de usar autenticacion sobre datos heredados.

Las sesiones HTTP usan `SESSION_DRIVER=file`, distinto de la tabla de dominio
`sesiones`. Compose ya define este valor; quien ejecute PHP fuera de Docker debe
actualizar tambien su `backend/.env` si aun tiene `SESSION_DRIVER=database`.
No apuntar las sesiones HTTP a `sesiones`.

### Reversion y datos

En una base desechable creada por las migraciones se puede comprobar:

```text
docker compose exec backend php artisan migrate:rollback --no-interaction
docker compose exec backend php artisan migrate --no-interaction
```

El rollback de creacion elimina tablas y sus datos. En tablas adoptadas de
`init.sql`, se bloquea deliberadamente para conservar los datos anteriores:
usar una nueva migracion de cambio. `migrate:fresh`, `db:wipe` y borrar volumenes
eluden esta proteccion y NO son instrucciones de actualizacion del proyecto.

Para una columna nueva usar una migracion con `Schema::table`, considerar datos
existentes (nullable o valor predeterminado) y probar su up/down en una copia.
Eliminar una columna tambien elimina sus valores: rollback no sustituye backups.

### Pruebas automatizadas PostgreSQL

`backend/tests/Feature/DomainMigrationsTest.php` usa una base aislada y revierte
sus cambios al terminar cada prueba. No utiliza la base del proyecto. Requiere
PostgreSQL 15 accesible en 127.0.0.1:55432, base `dinostar_issue22_test`, usuario
`postgres`, password de pruebas `issue22-test-only`, y dependencias Composer dev.
La base debe estar vacia. Desde `backend/`, en PowerShell:

```powershell
$env:DINOSTAR_MIGRATION_TEST='1'
php artisan test --compact tests/Feature/DomainMigrationsTest.php
Remove-Item Env:DINOSTAR_MIGRATION_TEST
```

En Bash:

```bash
DINOSTAR_MIGRATION_TEST=1 php artisan test --compact tests/Feature/DomainMigrationsTest.php
```

Sin esa variable, las pruebas PostgreSQL se omiten; no confundir omision con exito.

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
