-- Prueba de integridad para una base de PRUEBAS inicializada con init.sql.
-- Los datos son ficticios y todos los cambios se revierten con ROLLBACK.
\set ON_ERROR_STOP on
BEGIN;

CREATE FUNCTION pg_temp.expect_error(statement TEXT, expected_state TEXT)
RETURNS VOID LANGUAGE plpgsql AS $$
DECLARE received_state TEXT;
BEGIN
    BEGIN
        EXECUTE statement;
    EXCEPTION WHEN OTHERS THEN
        GET STACKED DIAGNOSTICS received_state = RETURNED_SQLSTATE;
        IF received_state = expected_state THEN
            RETURN;
        END IF;
        RAISE EXCEPTION 'SQLSTATE esperado %, recibido %', expected_state, received_state;
    END;
    RAISE EXCEPTION 'Se acepto una operacion que debia rechazarse: %', statement;
END;
$$;

DO $$
BEGIN
    IF (SELECT count(*) FROM information_schema.tables
        WHERE table_schema = 'public' AND table_name IN
        ('usuarios','pacientes','asignaciones','sesiones','lecturas',
         'alertas','atenciones_alerta','logs_auditoria')) <> 8 THEN
        RAISE EXCEPTION 'Faltan tablas del esquema';
    END IF;
    IF (SELECT count(*) FROM pg_constraint c JOIN pg_namespace n
        ON n.oid = c.connamespace WHERE n.nspname = 'public'
        AND c.contype = 'f' AND c.conname IN
        ('fk_asignaciones_enfermera','fk_asignaciones_paciente',
         'fk_sesiones_paciente','fk_sesiones_enfermera','fk_lecturas_sesion',
         'fk_alertas_sesion','fk_atenciones_alerta','fk_atenciones_enfermera',
         'fk_logs_usuario')) <> 9 THEN
        RAISE EXCEPTION 'Faltan claves foraneas';
    END IF;
END;
$$;

INSERT INTO usuarios (id,nombre,email,password,rol,turno)
VALUES (-1801,'Prueba SQL','issue18@example.invalid','hash-ficticio','ENFERMERA','MATUTINO');
INSERT INTO pacientes (id,nombre,numero_cama,sala)
VALUES (-1801,'Paciente ficticio','PRUEBA18','PRUEBA18');
INSERT INTO asignaciones (enfermera_id,paciente_id,turno,fecha_inicio)
VALUES (-1801,-1801,'MATUTINO',CURRENT_TIMESTAMP);
INSERT INTO sesiones (id,paciente_id,enfermera_id,vol_total,modo_goteo,estado,inicio)
VALUES (-1801,-1801,-1801,500,'NORMAL_GOTEO','ACTIVA',CURRENT_TIMESTAMP);
INSERT INTO lecturas (sesion_id,gotas_por_min,vol_restante,tiempo_restante_min,timestamp_dispositivo)
VALUES (-1801,20,400,60,CURRENT_TIMESTAMP);
INSERT INTO alertas (id,sesion_id,tipo,mensaje)
VALUES (-1801,-1801,'GOTEO_LENTO','Alerta ficticia');
INSERT INTO atenciones_alerta (alerta_id,enfermera_id,resolvio)
VALUES (-1801,-1801,TRUE);
INSERT INTO logs_auditoria (usuario_id,accion,ip,datos_nuevos)
VALUES (-1801,'PRUEBA_SQL','::1','{"prueba":true}');

SELECT pg_temp.expect_error($q$INSERT INTO usuarios (nombre,email,password,rol)
VALUES ('Prueba','null-turno@example.invalid','hash','ENFERMERA')$q$, '23514');
SELECT pg_temp.expect_error($q$INSERT INTO usuarios (nombre,email,password,rol)
VALUES ('Prueba','issue18@example.invalid','hash','ADMIN')$q$, '23505');
SELECT pg_temp.expect_error($q$INSERT INTO pacientes (nombre,numero_cama,sala)
VALUES ('Duplicado','prueba18','prueba18')$q$, '23505');
SELECT pg_temp.expect_error($q$INSERT INTO asignaciones (enfermera_id,paciente_id,turno,fecha_inicio)
VALUES (-1801,-1801,'MATUTINO',CURRENT_TIMESTAMP)$q$, '23505');
SELECT pg_temp.expect_error($q$INSERT INTO sesiones (paciente_id,enfermera_id,vol_total,modo_goteo,estado,inicio)
VALUES (-1801,-1801,500,'NORMAL_GOTEO','PAUSADA',CURRENT_TIMESTAMP)$q$, '23505');
SELECT pg_temp.expect_error($q$UPDATE sesiones SET estado='FINALIZADA' WHERE id=-1801$q$, '23514');
SELECT pg_temp.expect_error($q$UPDATE lecturas SET gotas_por_min=-1 WHERE sesion_id=-1801$q$, '23514');
SELECT pg_temp.expect_error($q$INSERT INTO alertas (sesion_id,tipo,mensaje)
VALUES (-1899,'FIN_BOLSA','Sin sesion')$q$, '23503');
SELECT pg_temp.expect_error($q$INSERT INTO atenciones_alerta (alerta_id,enfermera_id,resolvio)
VALUES (-1801,-1801,TRUE)$q$, '23505');
SELECT pg_temp.expect_error($q$DELETE FROM pacientes WHERE id=-1801$q$, '23503');

ROLLBACK;
\echo 'OK: 8 tablas, 9 FK, inserciones validas y 10 rechazos esperados; datos revertidos.'
