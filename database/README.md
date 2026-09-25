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

## Datos demo (tarea #35)

Desde la raiz del repositorio, con Docker encendido:

```text
docker compose up -d --build --wait
docker compose exec backend php artisan migrate --no-interaction
docker compose exec backend php artisan db:seed --no-interaction
```

`db:seed` llama a `DemoSeeder` y crea una enfermera (`enfermera@dinostar.com`,
password `password` almacenado con bcrypt), turno MATUTINO, activa; diez pacientes
Paciente 01..10 en Sala A, camas cama-01..cama-10; dos sesiones ACTIVA para los
primeros dos pacientes, NORMAL_GOTEO de 500 ml y MICRO_GOTEO de 250 ml; cinco
lecturas por sesion (diez en total), entre 20 y 60 gotas/minuto y volumen decreciente.

Para estos ejemplos se asumen factores de 20 gotas/ml en NORMAL y 60 en MICRO.
El tiempo restante se calcula como ceil(vol_restante * factor / gotas_por_min).
Los timestamps simulan cinco muestras separadas por un segundo desde el inicio;
no representan mediciones reales ni prescriben parametros clinicos.

El seeder solo funciona en APP_ENV local/testing, incluso con --force. No depende
de Faker ni requiere instalar dependencias dev dentro del contenedor. No se
ejecuta automaticamente al arrancar: correr el comando una vez tras las migraciones.
Los datos persisten al recrear contenedores mientras se conserve el volumen.

Se reservan IDs negativos -35001..-35010 por tabla para identificar los registros
demo sin afectar las secuencias de IDs positivos. Una segunda ejecucion conserva
los registros existentes, incluidos passwords, estados y lecturas modificados.
No reactiva sesiones finalizadas ni reinicia las pruebas. Si se borraron registros
demo, inserta los faltantes si no hay conflictos. Si un ID pertenece a otro
registro, el correo ya esta ocupado o una cama/sesion activa colisiona, se detiene
y revierte toda esa ejecucion; no borra ni sobrescribe los datos en conflicto.
No elimina una cuenta test@example.com creada por el seeder anterior.

En pgAdmin se puede verificar sin mostrar el hash de contrasena:

```sql
SELECT id, nombre, email, rol, turno, activo FROM usuarios WHERE id = -35001;
SELECT id, nombre, numero_cama, sala, activo FROM pacientes
WHERE id BETWEEN -35010 AND -35001 ORDER BY numero_cama;
SELECT id, paciente_id, enfermera_id, modo_goteo, vol_total, estado FROM sesiones
WHERE id IN (-35001, -35002);
SELECT sesion_id, count(*) AS lecturas_demo FROM lecturas
WHERE id BETWEEN -35010 AND -35001 GROUP BY sesion_id;
```

En una base nueva, los totales son 1 usuario, 10 pacientes, 2 sesiones y 10 lecturas.
Con datos anteriores, comprobar los registros demo por los filtros anteriores.
`verify.sql` sigue revirtiendo sus propias inserciones, pero NO elimina estos seeds:
despues de sembrar datos ya no se esperan tablas vacias.
Esto crea credenciales de prueba; no implementa el endpoint ni la pantalla de login.

Las pruebas `DemoSeederTest.php` utilizan el mismo PostgreSQL aislado y variable
DINOSTAR_MIGRATION_TEST explicados arriba. Verifican contenido, bcrypt, calculos,
repeticion sin cambios, conflictos atomicos y bloqueo fuera de local/testing.

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
