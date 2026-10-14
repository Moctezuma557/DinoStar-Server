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

Actualizar la rama antes de ejecutar Artisan. El backend comparte el codigo
local mediante un bind mount; sus dependencias `vendor` viven en el volumen
Docker `backend_vendor` para evitar lecturas lentas a traves de Windows/macOS.
La imagen incluye dependencias de `composer.lock` y el arranque las sincroniza.
Reconstruir al cambiar Dockerfile/dependencias y reiniciar procesos persistentes
como `mqtt:listen` despues de actualizar su codigo.

```text
docker compose up -d --build --wait
docker compose exec backend php artisan migrate --no-interaction
docker compose exec backend php artisan migrate:status
```

Si el backend queda `unhealthy`, revisar `docker compose logs --tail 50 backend`
y `docker inspect --format '{{json .State.Health}}' dinostar-backend`.
El healthcheck consulta la ruta Laravel `/up` (no solo un puerto abierto),
con un timeout HTTP de 8 s, limite de proceso de 10 s y margen inicial de 120 s.
Ese margen permite sincronizar Composer antes de servir HTTP; no convierte
una respuesta fallida en saludable. Tras cambiar esta configuracion, ejecutar:

```text
docker compose up -d --build --no-deps --wait --wait-timeout 240 backend
```

Este ultimo comando requiere la base ya encendida y recrea solo el backend.
No elimina volumenes PostgreSQL. La carpeta `backend/vendor` del anfitrion
permanece separada: para PHP local ejecutar Composer localmente; para Docker,
usar `docker compose exec backend composer install`. No copiar vendor entre SO.

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
primeros dos pacientes: cama-01 MICRO_GOTEO de 500 ml y cama-02 NORMAL_GOTEO
de 250 ml; cinco
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
los registros existentes, incluidos passwords, modos, estados y lecturas modificados.
Si una sesion conserva el modo anterior, muestra un aviso; revisar el comando
`demo:configurar-mqtt --dry-run` descrito en la seccion MQTT.
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

## Procesamiento de DataPackets (tarea #44)

`App\Services\DataPacketService::procesar(array $data)` recibe UN paquete ya
decodificado. Incluye los modelos Paciente, Sesion, Lectura y Alerta que faltaban
en Develop. La tarea #51 conecta el listener MQTT con este servicio; ver la
seccion de integracion mas abajo. No se implementa broadcasting.

Reconstruir el backend y ejecutar `php artisan migrate` dentro del contenedor
antes de probar. La nueva migracion convierte gotas_por_min de integer a numeric
sin modificar las migraciones historicas ni init.sql. Conserva datos y restricciones;
su rollback se bloquea si existen fracciones, para no redondear lecturas guardadas.

El servicio valida campos y rangos. Busca un paciente activo por numero_cama y
su sesion ACTIVA. Sin sesion, con camas ambiguas entre salas o modo incompatible,
registra warning sin insertar. Un paquete invalido produce ValidationException;
el consumidor debe manejarla. El timestamp se interpreta en milisegundos UTC.
La lectura y todas sus alertas se guardan en una transaccion. Los logs de exito
se emiten despues del commit. No se proporcionan IDs: PostgreSQL los genera.

Alertas independientes: volumen <50 produce FIN_BOLSA; gotas <20 produce
GOTEO_LENTO; gotas >60 produce GOTEO_RAPIDO. Se crea una por condicion cumplida,
con resuelta=false. No se recalcula el tiempo recibido ni se cambia la sesion.
Cada llamada valida con sesion compatible inserta una lectura nueva: este contrato
no incluye deduplicacion de reenvios ni rechazo de paquetes antiguos.

### Comprobacion manual en Windows o Mac

Usar una base de desarrollo con los datos demo y sesiones activas:

```text
docker compose up -d --build --wait
docker compose exec backend php artisan migrate --no-interaction
docker compose exec backend php artisan db:seed --no-interaction
docker compose exec -e XDG_CONFIG_HOME=/tmp -e XDG_DATA_HOME=/tmp -e XDG_CACHE_HOME=/tmp backend php artisan tinker
```

Si se conserva la demo anterior con cama-01 NORMAL, revisar y aplicar primero
`demo:configurar-mqtt` como se indica en la seccion MQTT. El ejemplo siguiente
requiere cama-01 MICRO y no cambia automaticamente el modo de la sesion.

Dentro de Tinker, ejecutar estas lineas PHP (no en PowerShell/Bash):

```php
$service = app(\App\Services\DataPacketService::class);
$packet = ['pacienteId' => 'cama-01', 'gotasPorMin' => 32.5, 'tiempoRestante' => 754, 'volRestante' => 408.5, 'modo' => 'MICRO_GOTEO', 'timestamp' => now()->getTimestampMs()];
$before = [\App\Models\Lectura::count(), \App\Models\Alerta::count()];
$service->procesar($packet);
[\App\Models\Lectura::count() - $before[0], \App\Models\Alerta::count() - $before[1]];
```

Resultado: [1, 0]. Para probar dos alertas simultaneas:

```php
$before = [\App\Models\Lectura::count(), \App\Models\Alerta::count()];
$service->procesar(array_replace($packet, ['gotasPorMin' => 12.3, 'volRestante' => 45.2]));
[\App\Models\Lectura::count() - $before[0], \App\Models\Alerta::count() - $before[1]];
```

Resultado: [1, 2], FIN_BOLSA y GOTEO_LENTO. Sin sesion activa:

```php
$before = [\App\Models\Lectura::count(), \App\Models\Alerta::count()];
$service->procesar(array_replace($packet, ['pacienteId' => 'cama-03']));
[\App\Models\Lectura::count() - $before[0], \App\Models\Alerta::count() - $before[1]];
exit
```

Resultado: [0, 0], siempre que cama-03 siga sin sesion activa. El seeder no
restablece sesiones modificadas: comprobar su estado si no se obtiene el resultado.
Estas pruebas GUARDAN datos y repetirlas agrega lecturas/alertas. No borrar volumenes.

Consultar en pgAdmin, conectado a dinostar_rex:

```sql
SELECT id, sesion_id, gotas_por_min, vol_restante, tiempo_restante_min,
       timestamp_dispositivo, created_at
FROM public.lecturas WHERE sesion_id = -35001 ORDER BY id DESC LIMIT 10;
SELECT id, sesion_id, tipo, mensaje, resuelta
FROM public.alertas WHERE sesion_id = -35001 ORDER BY id DESC LIMIT 10;
```

Compose configura LOG_CHANNEL=stderr: los logs de estas llamadas aparecen en
la terminal de Tinker. Los logs del proceso servidor se consultan con
`docker compose logs backend`. Si se utiliza un canal basado en archivo:

```text
docker compose exec backend tail -n 60 storage/logs/laravel.log
```

Las variables XDG del comando Tinker usan directorios temporales escribibles
por el usuario del contenedor y evitan el error de permisos en /var/www/.config/psysh.

Pruebas automaticas: `tests/Feature/DataPacketServiceTest.php` utiliza el mismo
PostgreSQL aislado y opt-in DINOSTAR_MIGRATION_TEST de la seccion anterior.
Ejecutar junto con DomainMigrationsTest y DemoSeederTest para comprobar regresiones.

## Integracion del simulador con MQTT y DataPacketService (tarea #51)

Flujo: `simulator/braquio_simulator.py` -> broker Mosquitto -> `mqtt:listen`
-> `DataPacketService` -> PostgreSQL.
Se utiliza el generador de la tarea #36, no una llamada manual desde Tinker.
El listener recibe una lista JSON y pasa cada objeto al servicio existente.
Un JSON incorrecto se descarta con warning. Un elemento invalido o un error
de procesamiento se registra y no impide procesar los demas elementos del lote
ni los siguientes mensajes. Los errores de persistencia no se reintentan.

La configuracion del receptor esta en `backend/config/mqtt.php`: MQTT_HOST,
MQTT_PORT y MQTT_TOPIC (por defecto `broker`, `1883` y
`hospital/braquio/telemetria`). Desde el anfitrion, el simulador utiliza
`localhost:1883`. Los parametros `--broker`, `--port` y `--topic` permiten
cambiar su destino. Si se cambia el topic, debe coincidir en ambos extremos.

### Recepcion con activacion desde el simulador (correccion actual)

El listener entrega el JSON directamente a `DataPacketService::procesarMensaje`.
`MqttPacketProcessor` queda como adaptador de compatibilidad para llamadas previas;
el listener ya no lo usa. Mosquitto sigue transportando los mensajes MQTT.
No hace falta ejecutar `db:seed` ni `demo:configurar-mqtt` en este flujo.

Con `--simulador`, cada paquete valido puede crear una sesion ACTIVA para su cama
registrada. Cuatro camas recibidas pueden generar cuatro sesiones activas, sin
limite de dos. Un nuevo envio con el mismo modo reutiliza la sesion; si cambia
el modo, finaliza la sesion anterior y crea otra para conservar el modo de las
lecturas historicas. La lectura y sus alertas se guardan en la misma transaccion
que la activacion: un fallo revierte todos los cambios de ese paquete.

Condiciones del flujo:

- Solo APP_ENV local/testing; es una opcion explicita para simulaciones.
- Los pacientes deben estar registrados, activos y tener numero_cama unico entre
  los pacientes vigentes. No se inventan identidades a partir de un numero de cama.
- La enfermera y el volumen inicial se indican al arrancar: el JSON no los envia.
  El volumen inicial es un parametro de la prueba, no una medicion del dispositivo.
  Para bolsas de distintos tamanos, configurar las sesiones con sus volumenes
  reales antes de iniciar; el parametro es comun a las sesiones nuevas.
- Una sesion PAUSADA no se reactiva automaticamente. Las camas ausentes de un
  lote no se activan ni se cierran por su ausencia; una omision puede ser temporal.
- Solo se activan camas que enviaron paquetes validos; los registros previos
  de otras camas no se eliminan. `pacientes.activo` no es `sesiones.estado`.
- El modo recibido es la fuente del modo de la nueva sesion. El simulador actual
  sin `--demo` elige modos aleatorios. Este cambio no modifica `simulator/` ni fuerza
  cama-01 MICRO/cama-02 NORMAL si el emisor envia otros modos.
- Sin `--simulador`, `mqtt:listen` conserva el comportamiento de #44: exige una
  sesion ACTIVA y modo coincidente; si no existe, registra warning y no inserta.

Usar la misma revision del backend en todos los equipos y detener el listener
anterior con Ctrl+C antes de iniciar el nuevo. Ejecutar desde la raiz:

```text
git branch --show-current
git log -1 --oneline
docker compose up -d --build --wait database broker backend
docker compose exec backend php artisan migrate --no-interaction
docker compose exec backend php artisan mqtt:listen --help
```

En pgAdmin, consultar la enfermera a asignar y las camas registradas:

```sql
SELECT id, nombre FROM usuarios WHERE rol = 'ENFERMERA' AND activo = true;
SELECT numero_cama, sala, activo FROM pacientes ORDER BY numero_cama;
SELECT count(*) AS lecturas FROM lecturas;
SELECT count(*) AS alertas FROM alertas;
```

En la primera terminal, cambiar -35001 por el ID obtenido si difiere. El siguiente
ejemplo usa volumen inicial de prueba de 500 mL para nuevas sesiones:

```text
docker compose exec backend php artisan mqtt:listen --simulador --enfermera=-35001 --volumen-inicial=500
```

Esperar `Escuchando hospital/braquio/telemetria`. Mantener un solo listener para
no duplicar registros. En la segunda terminal, Windows PowerShell, ejecutar cada
instruccion por separado. Si ya existe un entorno funcional, omitir su creacion.
En equipos con el lanzador oficial de Python, crear el entorno con:

```powershell
py -3 -m venv simulator/.venv
```

En esta laptop se preparo CPython oficial 3.13.16 en la distribucion NuGet,
porque la politica de Windows bloqueo el modulo `array` del Python de MSYS2.
El entorno ya quedo creado en `simulator/.venv/Scripts/python.exe`. Para
recrearlo en esta laptop, solo si falta, usar el interprete base explicitamente:

```powershell
& "$env:LOCALAPPDATA\Programs\Python\DinoStar313\tools\python.exe" -m venv simulator/.venv
```

No usar `python -m venv` sin comprobar antes `Get-Command python`: puede
seleccionar otra vez `C:\msys64\mingw64\bin\python.exe`. El respaldo del entorno
anterior se conservo en `simulator/venv/msys2-backup-20261013_215218`, excluido de
Git. No se cambiaron las politicas de Windows ni el PATH. La distribucion base
es local y no viaja por Git: cada equipo prepara su propio entorno virtual.

Comprobar el entorno nuevo y despues ejecutar los escenarios:

```powershell
& ./simulator/.venv/Scripts/python.exe -c "import array; print('array OK')"
& ./simulator/.venv/Scripts/python.exe -m pip --version
$pythonSim = if (Test-Path simulator/.venv/Scripts/python.exe) {
    './simulator/.venv/Scripts/python.exe'
} else {
    './simulator/.venv/bin/python.exe'
}
& $pythonSim -m pip install -r simulator/requirements.txt
& $pythonSim simulator/braquio_simulator.py --pacientes 4 --escenario normal --ciclo 1 --ciclos 3
& $pythonSim simulator/braquio_simulator.py --pacientes 4 --escenario combinado --ciclos 1
& $pythonSim simulator/braquio_simulator.py --pacientes 4 --escenario rapido --ciclos 1
```

En macOS/Linux, en esa segunda terminal (crear el entorno solo si no existe):

```bash
python3 -m venv simulator/.venv
simulator/.venv/bin/python -m pip install -r simulator/requirements.txt
simulator/.venv/bin/python simulator/braquio_simulator.py --pacientes 4 --escenario normal --ciclo 1 --ciclos 3
simulator/.venv/bin/python simulator/braquio_simulator.py --pacientes 4 --escenario combinado --ciclos 1
simulator/.venv/bin/python simulator/braquio_simulator.py --pacientes 4 --escenario rapido --ciclos 1
```

Sin otros publicadores y con las cuatro camas disponibles, los incrementos son:

| Prueba | Lecturas nuevas | Alertas nuevas |
|---|---:|---:|
| Normal, tres lotes | 12 | 0 |
| Combinado, un lote | 4 | 8 (4 FIN_BOLSA + 4 GOTEO_LENTO) |
| Rapido, un lote | 4 | 4 GOTEO_RAPIDO |
| Total de estos comandos | 20 | 12 |

Comparar incrementos, no IDs: puede haber historial previo. Los cambios de modo
pueden agregar sesiones FINALIZADA; debe seguir habiendo una ACTIVA por cama.
En pgAdmin comprobar, sin filtrar por los IDs negativos del seeder:

```sql
SELECT (SELECT count(*) FROM lecturas) AS lecturas,
       (SELECT count(*) FROM alertas) AS alertas;

SELECT p.numero_cama, s.id AS sesion_id, s.estado, s.modo_goteo
FROM sesiones s JOIN pacientes p ON p.id = s.paciente_id
WHERE s.estado = 'ACTIVA' ORDER BY p.numero_cama;

SELECT l.id, p.numero_cama, s.modo_goteo, l.gotas_por_min, l.vol_restante,
       l.tiempo_restante_min, l.timestamp_dispositivo
FROM lecturas l JOIN sesiones s ON s.id = l.sesion_id
JOIN pacientes p ON p.id = s.paciente_id
ORDER BY l.id DESC LIMIT 20;

SELECT a.id, p.numero_cama, a.tipo, a.mensaje, a.resuelta
FROM alertas a JOIN sesiones s ON s.id = a.sesion_id
JOIN pacientes p ON p.id = s.paciente_id
ORDER BY a.id DESC LIMIT 12;
```

Validacion de esta correccion: 36 pruebas PHP / 445 aserciones, sin omisiones,
incluyendo cuatro y diez camas sin seeder, limites 20/60, aislamiento de errores,
rollback completo, modos historicos y comportamiento previo. Con el archivo
original del simulador sin modificaciones, Mosquitto y PostgreSQL 15 aislados:
seis lotes de cuatro camas guardaron 24 lecturas y 12 alertas; cuatro sesiones
quedaron ACTIVA y cama-05..10 no se activaron. Se compararon campos y timestamps
con los JSON enviados. Un JSON invalido no impidio recibir el siguiente lote.
La base principal no se utilizo en estas pruebas. Esta revision se ejecuto en
Windows con backend Linux en Docker; repetir en macOS y en la revision compartida
antes de afirmar que todos los equipos estan verificados.

### Flujo anterior: sesiones preconfiguradas mediante demo

La seccion siguiente describe la alternativa sin `--simulador`, limitada a las
sesiones previamente activas. No es un requisito del flujo actual de activacion.

Desde la raiz del repositorio:

```text
docker compose up -d --build --wait database broker backend
docker compose exec backend php artisan migrate --no-interaction
docker compose exec backend php artisan db:seed --no-interaction
```

El contrato de demo acordado el 13/10/2026 es cama-01 con sesion ACTIVA
MICRO_GOTEO y cama-02 con sesion ACTIVA NORMAL_GOTEO; las demas camas no tienen
sesion activa. `pacientes.activo` identifica un paciente vigente y no sustituye
a `sesiones.estado`: puede haber diez pacientes y solo dos sesiones ACTIVA.

El seeder conserva las sesiones existentes. En una base con la demo anterior,
hacer respaldo y consultar primero la correccion propuesta:

```text
docker compose exec backend php artisan demo:configurar-mqtt --dry-run
docker compose exec backend php artisan demo:configurar-mqtt
```

El primer comando no escribe datos. El segundo cambia solo modo_goteo y
updated_at de las sesiones demo -35001/-35002 cuando el modo difiere; conserva
volumenes, lecturas y alertas anteriores, sin recalcular tiempos historicos.
Solo funciona en PostgreSQL local/testing. Si faltan registros, no coinciden
las camas, una sesion no esta ACTIVA o existen otras sesiones activas, se detiene
sin cambios. No reactiva ni cierra sesiones automaticamente. No se borran
volumenes ni se ejecuta `migrate:fresh`.

Comprobar en pgAdmin:

```sql
SELECT p.numero_cama, p.activo AS paciente_activo, s.id AS sesion_id,
       s.estado, s.modo_goteo
FROM pacientes p LEFT JOIN sesiones s ON s.paciente_id = p.id AND s.estado = 'ACTIVA'
WHERE p.numero_cama IN ('cama-01', 'cama-02', 'cama-03', 'cama-04')
ORDER BY p.numero_cama;
```

En una terminal que se mantiene abierta, iniciar UN receptor:

```text
docker compose exec backend php artisan mqtt:listen
```

Esperar `Escuchando hospital/braquio/telemetria`. Este proceso se inicia
manualmente; no arranca con el servidor HTTP. Los logs se muestran en esta
terminal porque Compose configura LOG_CHANNEL=stderr. Detener con Ctrl+C.
Evitar varios receptores del mismo topic: cada uno recibiria el lote y
guardaria otra copia de las lecturas.

En otra terminal, preparar Python 3 y el entorno del simulador solo si no existe.
En Windows usar el lanzador oficial (o la ruta de CPython oficial indicada arriba).
En macOS/Linux usar python3:

```text
py -3 -m venv simulator/.venv
```

Windows PowerShell, incluyendo Python de MSYS2:

```powershell
$pythonSim = if (Test-Path simulator/.venv/Scripts/python.exe) {
    './simulator/.venv/Scripts/python.exe'
} else {
    './simulator/.venv/bin/python.exe'
}
& $pythonSim -m pip install -r simulator/requirements.txt
& $pythonSim simulator/braquio_simulator.py --help
```

macOS/Linux (usar `python3 -m venv simulator/.venv` si no existe `python`):

```bash
simulator/.venv/bin/python -m pip install -r simulator/requirements.txt
simulator/.venv/bin/python simulator/braquio_simulator.py --help
```

Si aparece `unrecognized arguments` y la ayuda solo muestra `-p` y `-c`,
el archivo ejecutado no incluye la integracion. Comprobar `git branch --show-current`
y `git log -1 --oneline`, descargar la rama `feature/integracion-simulador-51`
y actualizarla antes de repetir. Confirmar las opciones con:

```bash
simulator/.venv/bin/python simulator/braquio_simulator.py --help
```

Escribir `--escenario` sin espacios entre los guiones y el nombre.
El aviso de actualizacion de pip no impide ejecutar el simulador.

**Compatibilidad del flujo anterior sin --simulador:** esta correccion no modifica archivos
de `simulator/`. En la version integrada anteriormente, `--demo` fija cama-01
a NORMAL_GOTEO y cama-02 a MICRO_GOTEO: es incompatible con el contrato actual.
Sin `--demo`, esa version elige modos aleatorios; quitar la opcion tampoco
garantiza compatibilidad. Confirmar con el responsable del simulador la rama y
un lote real con cama-01 MICRO_GOTEO y cama-02 NORMAL_GOTEO antes de repetir la
prueba conjunta. El backend rechaza diferencias de modo e informa cama,
sesion, modo_recibido y modo_esperado; no adapta silenciosamente las sesiones.

`--ciclos 1` publica un solo lote y termina. Sin esa opcion, se publican lotes
cada cinco segundos hasta Ctrl+C (`--ciclo` ajusta ese intervalo). El simulador
espera la conexion al broker; si no puede conectarse o publicar, termina con
error en lugar de anunciar envios que no ocurrieron.

### Casos de aceptacion

Con un emisor que cumpla el contrato anterior, estos son los incrementos por
lote de cuatro camas (una lectura por cama); esperar los logs del receptor
antes de consultar la base. Si el emisor admite `--escenario`, seleccionar
los valores de la tabla; comprobar los modos en el JSON enviado.

| Escenario | Lecturas nuevas | Alertas nuevas | Tipos |
| --- | ---: | ---: | --- |
| normal | 2 | 0 | Ninguno |
| fin-bolsa | 2 | 2 | FIN_BOLSA |
| lento | 2 | 2 | GOTEO_LENTO |
| rapido | 2 | 2 | GOTEO_RAPIDO |
| combinado | 2 | 4 | FIN_BOLSA y GOTEO_LENTO por sesion |

En todos los casos cama-03 y cama-04 generan warning y no agregan registros.
Un lote de cuatro camas agrega dos lecturas, no cuatro. Tres lotes normales
agregan seis lecturas: no existe un limite de dos lecturas acumuladas.
Las camas sin sesion activa no interrumpen los otros elementos del lote.
Los escenarios
son opciones de prueba del generador y calculan el tiempo restante; no representan
un tratamiento real. El modo por defecto `aleatorio` conserva la simulacion
variable. Los umbrales corregidos el 06/10/2026 son lento <20 y rapido >60;
20 y 60 exactos no generan alertas de goteo. Los escenarios lento/combinado
usan 19.9 gotas/min y rapido usa 60.1 para probar los nuevos limites.

### Diagnostico MQTT sin modificar el simulador

Para separar la persistencia del generador, se puede publicar un lote controlado
desde PowerShell con UN listener activo. Esta prueba agrega dos lecturas reales
a la base de desarrollo; no es una prueba del generador del companero.

```powershell
$marcaMqtt = [DateTimeOffset]::UtcNow.ToUnixTimeMilliseconds()
$loteMqtt = @(1..4 | ForEach-Object {
    $modoMqtt = if ($_ -eq 1) { 'MICRO_GOTEO' } else { 'NORMAL_GOTEO' }
    $factorMqtt = if ($_ -eq 1) { 60 } else { 20 }
    @{
        pacienteId = ('cama-{0:D2}' -f $_)
        gotasPorMin = 32.5
        tiempoRestante = [int][Math]::Floor(500 * $factorMqtt / 32.5)
        volRestante = 500.0
        modo = $modoMqtt
        timestamp = $marcaMqtt
    }
})
$loteMqtt | ConvertTo-Json -Depth 3 -Compress | docker compose exec -T broker mosquitto_pub -h localhost -t hospital/braquio/telemetria -s
if ($LASTEXITCODE -ne 0) { throw 'Fallo la publicacion MQTT' }
```

Esperado: lote recibido con paquetes=4, dos lecturas y warnings para camas 3/4.
Si aparece modo incompatible, comparar modo_recibido y modo_esperado. Si el
listener ni siquiera anuncia el lote, revisar que este conectado antes del
envio, el broker y el topic. Un contenedor backend healthy solo comprueba HTTP;
no significa que el proceso `mqtt:listen` este ejecutandose.

En pgAdmin, conectado a `dinostar_rex` (localhost:5433), consultar antes y despues:

```sql
SELECT count(*) AS lecturas FROM public.lecturas;
SELECT count(*) AS alertas FROM public.alertas;

SELECT l.id, p.numero_cama, s.modo_goteo, l.gotas_por_min,
       l.vol_restante, l.tiempo_restante_min, l.timestamp_dispositivo
FROM public.lecturas l
JOIN public.sesiones s ON s.id = l.sesion_id
JOIN public.pacientes p ON p.id = s.paciente_id
WHERE l.id > 0 AND s.id IN (-35001, -35002)
ORDER BY l.id DESC LIMIT 20;

SELECT id, sesion_id, tipo, mensaje, resuelta
FROM public.alertas WHERE sesion_id IN (-35001, -35002)
ORDER BY id DESC LIMIT 20;
```

Las nuevas lecturas y alertas tienen IDs generados por PostgreSQL; las sesiones
demo mantienen sus IDs negativos. Cada repeticion agrega nuevos registros.
Se mantienen los criterios de #44: decimales y timestamp en milisegundos,
alertas independientes, resuelta=false y warning sin inserciones si no hay sesion.

### Pruebas y alcance

`MqttPacketProcessorTest` cubre JSON incorrecto, formas no admitidas, continuidad
despues de errores y persistencia de lotes mixtos con PostgreSQL. La prueba de
persistencia requiere la base aislada y DINOSTAR_MIGRATION_TEST de la seccion
de migraciones. Desde backend, ejecutar las pruebas de backend y configuracion:

```text
php artisan test --compact tests/Feature/DomainMigrationsTest.php tests/Feature/DemoSeederTest.php tests/Feature/DataPacketServiceTest.php tests/Feature/MqttPacketProcessorTest.php tests/Feature/SesionTipoSolucionTest.php tests/Feature/ConfigureMqttDemoTest.php tests/Feature/SimulatorReceptionTest.php
```

Para las pruebas del generador, desde la raiz, usar el Python del entorno virtual.
En PowerShell, con `$pythonSim` definido como en la preparacion:

```powershell
& $pythonSim -m unittest discover -s simulator -p test_braquio_simulator.py -v
```

En macOS/Linux:

```bash
simulator/.venv/bin/python -m unittest discover -s simulator -p test_braquio_simulator.py -v
```

Validacion realizada el 05/10/2026 en Windows: 20 pruebas PHP, 237 aserciones,
sin omisiones, y 3 pruebas Python del generador. Tambien se construyo la imagen PHP 8.4
y se probo el recorrido real con Mosquitto y PostgreSQL 15 desechables: normal
(+2 lecturas/+0 alertas), combinado (+2/+4), rapido (+2/+2) y warning para cama-03.
Despues de publicar JSON y elementos invalidos, otro lote normal agrego dos
lecturas sin alertas y el receptor siguio activo.
La repeticion de estas pruebas en macOS queda pendiente.

El 06/10/2026 se ajustaron los limites de goteo a 20/60. La regresion en
PostgreSQL aislado paso con 20 pruebas PHP y 245 aserciones; las 4 pruebas
Python tambien pasaron. Se verifican 19.9 y 60.1 como alertas, y 20 y 60
sin alertas de goteo. Los registros historicos no se recalculan ni se borran.
Tras descargar esta correccion, reconstruir el backend con Compose y volver
a iniciar `mqtt:listen` para cargar el servicio actualizado.

El 13/10/2026 se reprodujo el contrato nuevo contra los seeds anteriores:
un lote de cuatro camas guardaba cero lecturas por modos invertidos. Tras la
correccion pasaron 30 pruebas PHP con 338 aserciones en PostgreSQL aislado.
Se probaron ademas Mosquitto, el listener real y PostgreSQL desechables con
seis lotes de cuatro camas: 12 lecturas nuevas (seis por sesion), ocho alertas
(cuatro GOTEO_LENTO, dos GOTEO_RAPIDO y dos FIN_BOLSA) y ninguna sesion nueva.
Un JSON invalido entre los lotes no impidio guardar el siguiente.
Esta prueba utilizo un publicador de diagnostico, no el simulador: la verificacion
con la version del generador que cumpla el contrato y en macOS queda pendiente.
El dashboard de esta revision utiliza datos estaticos; guardar en PostgreSQL
no demuestra que la interfaz este conectada a las lecturas MQTT.

Se mantiene QoS 0 y sesion MQTT limpia: el receptor debe estar conectado antes
de publicar. No hay deduplicacion, reintentos persistentes, recuperacion de lotes
perdidos. Los cambios de sesion requieren la opcion --simulador. Ante una desconexion del broker,
reiniciar `mqtt:listen`. La confirmacion de publicacion del simulador no confirma
por si misma la persistencia: comprobar los logs del servicio y PostgreSQL.

## Tipo de solucion de la sesion (tarea #60)

La nueva migracion agrega `sesiones.tipo_solucion VARCHAR(255) NULL` y el
modelo `Sesion` permite asignarlo mediante `$fillable`. La tarea no especifica
longitud ni obligatoriedad: se usan 255 caracteres y NULL para conservar las
sesiones existentes y permitir que los flujos anteriores sigan omitiendo el campo.
No se asigna una solucion ficticia a los datos historicos ni se modifica `init.sql`.

Desde la raiz, en Windows PowerShell o macOS/Linux, ejecutar por separado:

```text
docker compose up -d --build --wait database backend
docker compose exec backend php artisan migrate --no-interaction
docker compose exec backend php artisan migrate:status
```

En pgAdmin, sobre `dinostar_rex`, comprobar la columna y las sesiones:

```sql
SELECT column_name, data_type, character_maximum_length, is_nullable
FROM information_schema.columns
WHERE table_schema = 'public' AND table_name = 'sesiones'
  AND column_name = 'tipo_solucion';

SELECT id, paciente_id, tipo_solucion, estado FROM public.sesiones ORDER BY id;
```

Resultado esperado de la primera consulta: `tipo_solucion`, `character varying`,
`255`, `YES`. Las sesiones previas mantienen sus datos y tienen NULL en el campo.

Para comprobar la creacion mediante Eloquent en una base local con DemoSeeder,
abrir Tinker (las variables XDG evitan el problema de permisos de PsySH):

```text
docker compose exec backend php artisan db:seed --no-interaction
docker compose exec -e XDG_CONFIG_HOME=/tmp -e XDG_DATA_HOME=/tmp -e XDG_CACHE_HOME=/tmp backend php artisan tinker
```

Ejecutar estas lineas dentro de Tinker, no en PowerShell ni zsh:

```php
\Illuminate\Support\Facades\DB::beginTransaction();
$sesion = \App\Models\Sesion::create(['paciente_id' => -35003, 'enfermera_id' => -35001, 'vol_total' => 500, 'tipo_solucion' => 'Solucion de prueba', 'modo_goteo' => 'NORMAL_GOTEO', 'estado' => 'FINALIZADA', 'inicio' => now(), 'fin' => now()]);
$sesion->fresh()->only(['id', 'paciente_id', 'tipo_solucion', 'estado']);
\Illuminate\Support\Facades\DB::rollBack();
exit
```

La consulta `fresh()` debe recuperar `Solucion de prueba` desde PostgreSQL.
Se utiliza una sesion finalizada para no ocupar una cama activa. El rollback de
esta transaccion elimina solamente la insercion de prueba; por eso no aparecera
despues en pgAdmin. La secuencia puede consumir un ID aunque se revierta la fila.

`SesionTipoSolucionTest` verifica actualizacion con datos previos, tipo y longitud
de columna, creacion con/sin el nuevo dato, repeticion del seeder y migrate,
y reversion/reaplicacion de la migracion. Usa la misma base aislada y opt-in
`DINOSTAR_MIGRATION_TEST` descritos en la seccion de pruebas PostgreSQL.

El `down()` de la migracion elimina la columna y cualquier valor que contenga;
no ejecutar rollback sobre datos que se necesiten conservar. Este cambio permite
persistir el campo con Eloquent; formularios y endpoints quedan fuera de esta tarea.

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
