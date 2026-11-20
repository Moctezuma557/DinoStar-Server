import { useCallback, useEffect, useRef, useState } from "react";

import { ErrorDeApi, pedirJson } from "../services/api";
import "./dispositivos.css";

const RUTA_LOGS = "/api/dispositivos/logs";

const INTERVALO_SONDEO_MS = 5000;

const TIPOS_CONOCIDOS = {
  session_error: { clase: "registros-etiqueta--alerta", texto: "Sesión inválida" },
  data_inconsistency: { clase: "registros-etiqueta--atencion", texto: "Dato incoherente" },
  warning: { clase: "registros-etiqueta--atencion", texto: "Advertencia" },
  error: { clase: "registros-etiqueta--alerta", texto: "Error" },
  info: { clase: "registros-etiqueta--informativo", texto: "Informativo" },
};

function describirTipo(tipo) {
  const conocido = TIPOS_CONOCIDOS[tipo];
  if (conocido) return conocido;
  return { clase: "registros-etiqueta--desconocido", texto: tipo || "Sin tipo" };
}

function formatearFecha(iso) {
  if (!iso) return "—";
  const fecha = new Date(iso);
  if (Number.isNaN(fecha.getTime())) return "—";
  return fecha.toLocaleString("es-MX", {
    year: "numeric",
    month: "2-digit",
    day: "2-digit",
    hour: "2-digit",
    minute: "2-digit",
    second: "2-digit",
    hour12: false,
  });
}

function formatearHora(fecha) {
  if (!fecha) return null;
  return fecha.toLocaleTimeString("es-MX", { hour12: false });
}

function mensajeDeError(fallo) {
  if (fallo instanceof ErrorDeApi) {
    if (fallo.estado === 401) {
      return "Tu sesión expiró. Vuelve a iniciar sesión para consultar los registros.";
    }
    if (fallo.estado === 403) {
      return "Esta sección requiere una cuenta con rol de administrador.";
    }
    if (fallo.estado === 404) {
      return "Este servidor no expone el servicio de registros.";
    }
    return fallo.message;
  }
  return "No se pudo contactar al servidor. Revisa que el backend esté en ejecución.";
}

function useRegistrosDelSistema() {
  const [registros, setRegistros] = useState([]);
  const [total, setTotal] = useState(0);
  const [cargandoInicial, setCargandoInicial] = useState(true);
  const [error, setError] = useState(null);
  const [actualizadoEn, setActualizadoEn] = useState(null);

  const peticionRef = useRef(null);

  const consultar = useCallback(async () => {
    peticionRef.current?.abort();
    const control = new AbortController();
    peticionRef.current = control;

    try {
      const respuesta = await pedirJson(RUTA_LOGS, { signal: control.signal });
      const filas = Array.isArray(respuesta?.data) ? respuesta.data : [];
      setRegistros(filas);
      setTotal(Number.isFinite(respuesta?.total) ? respuesta.total : filas.length);

      setError(null);
      setActualizadoEn(new Date());
    } catch (fallo) {
      if (fallo?.name === "AbortError") return;
      setError(mensajeDeError(fallo));
    } finally {
      if (!control.signal.aborted) setCargandoInicial(false);
    }
  }, []);

   useEffect(() => {
    /*
     * Primera carga siempre, aunque la pestaña arranque en segundo plano.
     *
     * La regla react-hooks/set-state-in-effect marca la línea de abajo, pero
     * aquí es un falso positivo: `consultar` es asíncrona y su parte síncrona
     * se limita a abortar la petición anterior y crear el AbortController.
     * Todos los setState ocurren después del `await`, en otro turno del bucle
     * de eventos y ya fuera del cuerpo del efecto, así que no provocan el
     * render en cascada que la regla previene. El analizador no puede verlo
     * porque solo observa la llamada, no lo que sucede dentro de ella.
     */
    // eslint-disable-next-line react-hooks/set-state-in-effect
    consultar();

    let intervalo = null;

    const arrancar = () => {
      if (intervalo === null) intervalo = setInterval(consultar, INTERVALO_SONDEO_MS);
    };

    const detener = () => {
      if (intervalo !== null) {
        clearInterval(intervalo);
        intervalo = null;
      }
    };

    const alCambiarVisibilidad = () => {
      if (document.visibilityState === "visible") {
        consultar();
        arrancar();
      } else {
        detener();
      }
    };

    if (document.visibilityState === "visible") arrancar();
    document.addEventListener("visibilitychange", alCambiarVisibilidad);

    return () => {
      document.removeEventListener("visibilitychange", alCambiarVisibilidad);
      detener();
      peticionRef.current?.abort();
    };
  }, [consultar]);

  return { registros, total, cargandoInicial, error, actualizadoEn, refrescar: consultar };
}

function ModalRegistro({ registro, alCerrar }) {
  const botonCerrarRef = useRef(null);
  const elementoPrevioRef = useRef(null);

  useEffect(() => {
    elementoPrevioRef.current = document.activeElement;
    botonCerrarRef.current?.focus();

    function alTeclear(evento) {
      if (evento.key === "Escape") alCerrar();
    }
    document.addEventListener("keydown", alTeclear);

    const desbordePrevio = document.body.style.overflow;
    document.body.style.overflow = "hidden";

    return () => {
      document.removeEventListener("keydown", alTeclear);
      document.body.style.overflow = desbordePrevio;
      elementoPrevioRef.current?.focus?.();
    };
  }, [alCerrar]);

  const tipo = describirTipo(registro.tipo);

  return (
    <div className="registros-modal-fondo" onClick={alCerrar}>
      <div
        className="registros-modal"
        role="dialog"
        aria-modal="true"
        aria-labelledby="registros-modal-titulo"
        onClick={(evento) => evento.stopPropagation()}
      >
        <header className="registros-modal-cabecera">
          <h3 id="registros-modal-titulo">Registro #{registro.id}</h3>
          <button
            ref={botonCerrarRef}
            type="button"
            className="registros-modal-cerrar"
            onClick={alCerrar}
            aria-label="Cerrar el detalle del registro"
          >
            ×
          </button>
        </header>

        <div className="registros-modal-cuerpo">
          <div className="registros-campo">
            <span className="registros-campo-etiqueta">Tipo</span>
            <span className={`registros-etiqueta ${tipo.clase}`}>{tipo.texto}</span>
          </div>

          <div className="registros-campo">
            <span className="registros-campo-etiqueta">Categoría</span>
            <span className="registros-campo-valor">{registro.categoria ?? "—"}</span>
          </div>

          <div className="registros-campo">
            <span className="registros-campo-etiqueta">Paciente</span>
            <span className="registros-campo-valor">
              {registro.paciente_id ?? "Sin paciente asociado"}
            </span>
          </div>

          <div className="registros-campo">
            <span className="registros-campo-etiqueta">Cama</span>
            <span className="registros-campo-valor">{registro.numero_cama ?? "—"}</span>
          </div>

          <div className="registros-campo">
            <span className="registros-campo-etiqueta">Sesión</span>
            <span className="registros-campo-valor">
              {registro.sesion_id ?? "Sin sesión asociada"}
            </span>
          </div>

          <div className="registros-campo">
            <span className="registros-campo-etiqueta">Registrado el</span>
            <span className="registros-campo-valor">{formatearFecha(registro.created_at)}</span>
          </div>

          <div className="registros-campo registros-campo--bloque">
            <span className="registros-campo-etiqueta">Mensaje completo</span>
            <p className="registros-caja-mensaje">{registro.mensaje ?? "—"}</p>
          </div>
        </div>

        <footer className="registros-modal-pie">
          <button type="button" className="registros-boton" onClick={alCerrar}>
            Cerrar
          </button>
        </footer>
      </div>
    </div>
  );
}

export default function RegistrosDelSistema() {
  const { registros, total, cargandoInicial, error, actualizadoEn, refrescar } =
    useRegistrosDelSistema();

  const [seleccionado, setSeleccionado] = useState(null);
  const cerrarDetalle = useCallback(() => setSeleccionado(null), []);

  function renderizarContenido() {
    if (cargandoInicial) {
      return <p className="registros-aviso">Cargando registros…</p>;
    }

    if (error) {
      return (
        <div className="registros-aviso registros-aviso--error" role="alert">
          <p>{error}</p>
          <button type="button" className="registros-boton" onClick={refrescar}>
            Reintentar
          </button>
        </div>
      );
    }

    if (registros.length === 0) {
      return (
        <p className="registros-aviso">
          No hay registros todavía. Aquí aparecerán las sesiones inválidas y las
          lecturas incoherentes que detecte el servidor.
        </p>
      );
    }

    return (
      <div className="registros-tabla-envoltura">
        <table className="registros-tabla">
          <thead>
            <tr>
              <th scope="col">ID</th>
              <th scope="col">Tipo</th>
              <th scope="col">Categoría</th>
              <th scope="col">Mensaje</th>
              <th scope="col">Cama</th>
              <th scope="col">Fecha y hora</th>
            </tr>
          </thead>
          <tbody>
            {registros.map((registro) => {
              const tipo = describirTipo(registro.tipo);
              return (
                <tr
                  key={registro.id}
                  className="registros-fila"
                  onClick={() => setSeleccionado(registro)}
                >
                  <td>
                    {}
                    <button
                      type="button"
                      className="registros-boton-id"
                      onClick={(evento) => {
                        evento.stopPropagation();
                        setSeleccionado(registro);
                      }}
                      aria-label={`Ver el detalle del registro ${registro.id}`}
                    >
                      #{registro.id}
                    </button>
                  </td>
                  <td>
                    <span className={`registros-etiqueta ${tipo.clase}`}>{tipo.texto}</span>
                  </td>
                  <td>{registro.categoria ?? "—"}</td>
                  <td className="registros-celda-mensaje" title={registro.mensaje ?? ""}>
                    {registro.mensaje ?? "—"}
                  </td>
                  <td>{registro.numero_cama ?? "—"}</td>
                  <td className="registros-celda-fecha">{formatearFecha(registro.created_at)}</td>
                </tr>
              );
            })}
          </tbody>
        </table>
      </div>
    );
  }

  return (
    <div className="registros-pagina">
      <header className="registros-encabezado">
        <div className="registros-encabezado-fila">
          <h2 className="registros-titulo">Registros del sistema</h2>
          <span className="registros-etiqueta registros-etiqueta--informativo">
            Solo lectura
          </span>
        </div>
        <p className="registros-subtitulo">
          Bitácora de sesiones inválidas e inconsistencias de datos detectadas por el
          servidor.
        </p>
      </header>

      <section className="registros-panel">
        <div className="registros-panel-cabecera">
          <span className="registros-panel-titulo">Últimos registros</span>
          <div className="registros-panel-meta">
            {actualizadoEn && !error && (
              <span className="registros-chip registros-chip--tenue">
                Actualizado {formatearHora(actualizadoEn)}
              </span>
            )}
            <span className="registros-chip">
              {total} {total === 1 ? "registro" : "registros"}
            </span>
          </div>
        </div>

        {renderizarContenido()}
      </section>

      {seleccionado && <ModalRegistro registro={seleccionado} alCerrar={cerrarDetalle} />}
    </div>
  );
}