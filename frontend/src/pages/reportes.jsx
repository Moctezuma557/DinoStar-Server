import { Fragment, useEffect, useState } from "react";
import Icono from "../components/Icono";
import {
  obtenerReporte,
  obtenerSesiones,
  obtenerAuditoriaSesion,
  SESIONES_POR_PAGINA,
} from "../services/reportes";
import { obtenerEnfermeras } from "../services/enfermeras";
import { obtenerPacientes } from "../services/pacientes";
import "./reportes.css";

/* ------------------------------------------------------------------ */
/* Formato: null => "—" (nunca 0)                                      */
/* ------------------------------------------------------------------ */

function formatearNumero(valor) {
  if (valor === null || valor === undefined) return "—";
  return Number(valor).toLocaleString("es-MX");
}

function formatearPorcentaje(valor) {
  if (valor === null || valor === undefined) return "—";
  return `${Number(valor).toFixed(1)}%`;
}

function formatearMinutos(segundos) {
  if (segundos === null || segundos === undefined) return "—";
  return (segundos / 60).toFixed(1);
}

function formatearDuracion(segundos) {
  if (segundos === null || segundos === undefined) return "—";
  const m = Math.floor(segundos / 60);
  const s = Math.round(segundos % 60);
  return `${m}m ${String(s).padStart(2, "0")}s`;
}

function hoyISO() {
  return new Date().toISOString().slice(0, 10);
}

function primerDiaMesISO() {
  const d = new Date();
  return new Date(d.getFullYear(), d.getMonth(), 1).toLocaleDateString("sv-SE");
}

const FILTROS_INICIALES = {
  desde: primerDiaMesISO(),
  hasta: hoyISO(),
  busqueda: "",
  sala: "",
  enfermera: "",
};

const CLASE_CATEGORIA = {
  GOTEO_LENTO: "lento",
  FIN_BOLSA: "bolsa",
  GOTEO_RAPIDO: "rapido",
};


/* ------------------------------------------------------------------ */
/* V2: formato de fechas y helpers de la tabla de sesiones             */
/* ------------------------------------------------------------------ */

function formatearFecha(iso) {
  if (!iso) return "—";
  return new Date(iso).toLocaleDateString("es-MX", {
    day: "2-digit",
    month: "short",
    year: "numeric",
  });
}

function formatearHora(iso, doceHoras = false) {
  if (!iso) return "—";
  return new Date(iso).toLocaleTimeString("es-MX", {
    hour: "2-digit",
    minute: "2-digit",
    hour12: doceHoras,
  });
}

function formatearDuracionLarga(segundos) {
  if (segundos === null || segundos === undefined) return "—";
  const minutosTotales = Math.round(segundos / 60);
  const h = Math.floor(minutosTotales / 60);
  const m = minutosTotales % 60;
  return h > 0 ? `${h}h ${String(m).padStart(2, "0")}m` : `${m}m`;
}

function iniciales(nombre) {
  return (nombre ?? "")
    .split(" ")
    .filter((parte) => parte.length > 1 && !parte.includes("."))
    .slice(0, 2)
    .map((parte) => parte[0])
    .join("")
    .toUpperCase();
}

// Estados conocidos de sesiones; cualquier otro se muestra tal cual.
const ESTADOS_SESION = {
  FINALIZADA: { texto: "Completada", clase: "normal" },
  ACTIVA: { texto: "Activa", clase: "primario" },
};

function infoEstado(estado) {
  if (!estado) return { texto: "—", clase: "neutro" };
  return (
    ESTADOS_SESION[estado] ?? {
      texto: estado.charAt(0) + estado.slice(1).toLowerCase(),
      clase: "neutro",
    }
  );
}

function claseAlertas(cantidad) {
  if (cantidad === 0) return "neutro";
  return cantidad === 1 ? "atencion" : "alerta";
}

// [1, 2, 3, "gap-8", 9]: siempre primera, última y la actual con sus vecinas.
function paginasVisibles(actual, total) {
  const numeros = [...new Set([1, total, actual - 1, actual, actual + 1])]
    .filter((n) => n >= 1 && n <= total)
    .sort((a, b) => a - b);

  const resultado = [];
  numeros.forEach((n, i) => {
    if (i > 0 && n - numeros[i - 1] > 1) resultado.push(`gap-${n}`);
    resultado.push(n);
  });
  return resultado;
}

/* ------------------------------------------------------------------ */
/* Auditoría de alertas de una sesión (fila expandida)                 */
/* ------------------------------------------------------------------ */

function AuditoriaSesion({ sesionId }) {
  const [respuesta, setRespuesta] = useState({
    clave: null,
    alertas: null,
    error: null,
  });
  const [reintento, setReintento] = useState(0);

  const clave = `${sesionId}|${reintento}`;
  const cargando = respuesta.clave !== clave;

  useEffect(() => {
    const control = new AbortController();

    obtenerAuditoriaSesion(sesionId, control.signal)
      .then((alertas) => {
        if (!control.signal.aborted) {
          setRespuesta({ clave, alertas, error: null });
        }
      })
      .catch(() => {
        if (!control.signal.aborted) {
          setRespuesta({
            clave,
            alertas: null,
            error: "No se pudo cargar la auditoría de esta sesión.",
          });
        }
      });

    return () => control.abort();
  }, [sesionId, clave]);

  const alertas = respuesta.alertas;

  return (
    <div className="auditoria">
      <h4 className="auditoria__titulo">
        <Icono nombre="senal" tamano={16} />
        Auditoría de alertas de la sesión
        {alertas && (
          <span className="texto-secundario auditoria__conteo">
            {alertas.length} {alertas.length === 1 ? "evento" : "eventos"}
          </span>
        )}
      </h4>

      {cargando && <p className="texto-secundario">Cargando alertas…</p>}

      {!cargando && respuesta.error && (
        <p className="auditoria__error" role="alert">
          {respuesta.error}{" "}
          <button
            type="button"
            className="btn btn--contorno"
            onClick={() => setReintento((n) => n + 1)}
          >
            Reintentar
          </button>
        </p>
      )}

      {!cargando && alertas && alertas.length === 0 && (
        <p className="texto-secundario">Esta sesión no tiene alertas registradas.</p>
      )}

      {!cargando && alertas && alertas.length > 0 && (
        <div className="sesiones__scroll">
          <table className="auditoria__tabla">
            <thead>
              <tr>
                <th scope="col">Tipo de alerta</th>
                <th scope="col">Hora</th>
                <th scope="col">Descripción</th>
                <th scope="col">Atendida por</th>
                <th scope="col" className="num">Tiempo de respuesta</th>
              </tr>
            </thead>
            <tbody>
              {alertas.map((a, i) => (
                <tr key={`${a.id}-${i}`}>
                  <td>
                    <span
                      className={`etiqueta etiqueta--${CLASE_CATEGORIA[a.tipo] ?? "neutro"}`}
                    >
                      {a.tipo}
                    </span>
                  </td>
                  <td>{formatearHora(a.hora, true)}</td>
                  <td>{a.descripcion ?? "—"}</td>
                  <td>{a.atendida_por ?? "Sin atender"}</td>
                  <td className="num">{formatearDuracion(a.tiempo_respuesta_seg)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </div>
  );
}

/* ------------------------------------------------------------------ */
/* Tabla de sesiones auditadas + paginación del backend                */
/* ------------------------------------------------------------------ */

function TablaSesiones({ filtros }) {
  const filtrosClave = JSON.stringify(filtros);

  // Al cambiar los filtros se vuelve a la página 1 sin usar un efecto:
  // la página guardada solo vale para los filtros con los que se eligió.
  const [seleccion, setSeleccion] = useState({ clave: filtrosClave, pagina: 1 });
  const pagina = seleccion.clave === filtrosClave ? seleccion.pagina : 1;

  const [abiertaId, setAbiertaId] = useState(null);
  const [reintento, setReintento] = useState(0);
  const [respuesta, setRespuesta] = useState({
    clave: null,
    datos: null,
    error: null,
  });

  const clave = `${filtrosClave}|${pagina}|${reintento}`;
  const cargando = respuesta.clave !== clave;

  useEffect(() => {
    const control = new AbortController();

    obtenerSesiones(filtros, { pagina, porPagina: SESIONES_POR_PAGINA }, control.signal)
      .then((datos) => {
        if (!control.signal.aborted) {
          setRespuesta({ clave, datos, error: null });
        }
      })
      .catch(() => {
        if (!control.signal.aborted) {
          setRespuesta({
            clave,
            datos: null,
            error: "No se pudieron cargar las sesiones.",
          });
        }
      });

    return () => control.abort();
  }, [filtros, pagina, clave]);

  function irAPagina(n) {
    setAbiertaId(null);
    setSeleccion({ clave: filtrosClave, pagina: n });
  }

  const datos = respuesta.datos;
  const sinFilas = datos && datos.filas.length === 0;

  return (
    <div className="reportes__panel sesiones">
      <header className="sesiones__encabezado">
        <div>
          <h2>Registro histórico de sesiones auditadas</h2>
          <p className="texto-secundario">
            Sesiones de infusión del rango seleccionado, de la más reciente a la
            más antigua.
          </p>
        </div>
        {!cargando && datos && (
          <span className="insignia insignia--primario">
            {formatearNumero(datos.total)} registros
          </span>
        )}
      </header>

      {cargando && (
        <div className="reportes__estado" role="status">
          Cargando sesiones…
        </div>
      )}

      {!cargando && respuesta.error && (
        <div className="reportes__estado reportes__estado--error" role="alert">
          <p>{respuesta.error}</p>
          <button
            type="button"
            className="btn btn--primario"
            onClick={() => setReintento((n) => n + 1)}
          >
            Reintentar
          </button>
        </div>
      )}

      {!cargando && sinFilas && (
        <div className="reportes__estado">
          No hay sesiones para los filtros seleccionados.
        </div>
      )}

      {!cargando && datos && !sinFilas && (
        <>
          <div className="sesiones__scroll">
            <table className="sesiones__tabla">
              <thead>
                <tr>
                  <th scope="col">
                    <span className="sr-only">Ver alertas</span>
                  </th>
                  <th scope="col">Paciente</th>
                  <th scope="col">Enfermera asignada</th>
                  <th scope="col">Ubicación</th>
                  <th scope="col">Horario inicio/fin</th>
                  <th scope="col">Duración</th>
                  <th scope="col">Solución / volumen</th>
                  <th scope="col">Alertas</th>
                  <th scope="col">Estado</th>
                </tr>
              </thead>
              <tbody>
                {datos.filas.map((s) => {
                  const abierta = abiertaId === s.id;
                  const estado = infoEstado(s.estado);

                  return (
                    <Fragment key={s.id}>
                      <tr className={abierta ? "sesiones__fila sesiones__fila--abierta" : "sesiones__fila"}>
                        <td>
                          <button
                            type="button"
                            className={abierta ? "sesiones__toggle sesiones__toggle--abierto" : "sesiones__toggle"}
                            aria-expanded={abierta}
                            aria-label={`${abierta ? "Ocultar" : "Ver"} alertas de la sesión de ${s.paciente}`}
                            onClick={() => setAbiertaId(abierta ? null : s.id)}
                          >
                            <Icono nombre="flechaAbajo" tamano={16} />
                          </button>
                        </td>
                        <td>
                          <div className="sesiones__paciente">
                            <span className="avatar" aria-hidden="true">
                              {iniciales(s.paciente)}
                            </span>
                            <strong>{s.paciente}</strong>
                          </div>
                        </td>
                        <td>{s.enfermera ?? "—"}</td>
                        <td>
                          <span className="insignia insignia--ubicacion">
                            {s.sala ?? "—"}
                          </span>
                          {s.cama && <small className="sesiones__cama">{s.cama}</small>}
                        </td>
                        <td>
                          <div>{formatearFecha(s.inicio)}</div>
                          <small>
                            {formatearHora(s.inicio)} – {s.fin ? formatearHora(s.fin) : "En curso"}
                          </small>
                        </td>
                        <td className="sesiones__duracion">
                          {formatearDuracionLarga(s.duracion_segundos)}
                        </td>
                        <td>
                          <div>{s.solucion ?? "Sin solución registrada"}</div>
                          <small>
                            {s.volumen === null ? "—" : `${formatearNumero(s.volumen)} ml`}
                          </small>
                        </td>
                        <td>
                          <span className={`insignia insignia--${claseAlertas(s.numero_alertas)}`}>
                            {s.numero_alertas} {s.numero_alertas === 1 ? "alerta" : "alertas"}
                          </span>
                        </td>
                        <td>
                          <span className={`insignia insignia--${estado.clase}`}>
                            {estado.texto}
                          </span>
                        </td>
                      </tr>

                      {abierta && (
                        <tr className="sesiones__detalle">
                          <td colSpan={9}>
                            <AuditoriaSesion sesionId={s.id} />
                          </td>
                        </tr>
                      )}
                    </Fragment>
                  );
                })}
              </tbody>
            </table>
          </div>

          <footer className="paginacion">
            <p className="texto-secundario">
              Mostrando {formatearNumero(datos.desde)} – {formatearNumero(datos.hasta)} de{" "}
              {formatearNumero(datos.total)} sesiones
            </p>

            <nav className="paginacion__botones" aria-label="Paginación de sesiones">
              <button
                type="button"
                className="btn btn--contorno"
                disabled={datos.pagina <= 1}
                onClick={() => irAPagina(datos.pagina - 1)}
              >
                Anterior
              </button>

              {paginasVisibles(datos.pagina, datos.totalPaginas).map((n) =>
                typeof n === "string" ? (
                  <span key={n} className="paginacion__puntos" aria-hidden="true">
                    …
                  </span>
                ) : (
                  <button
                    key={n}
                    type="button"
                    className={n === datos.pagina ? "paginacion__num paginacion__num--activa" : "paginacion__num"}
                    aria-current={n === datos.pagina ? "page" : undefined}
                    onClick={() => irAPagina(n)}
                  >
                    {n}
                  </button>
                )
              )}

              <button
                type="button"
                className="btn btn--contorno"
                disabled={datos.pagina >= datos.totalPaginas}
                onClick={() => irAPagina(datos.pagina + 1)}
              >
                Siguiente
              </button>
            </nav>
          </footer>
        </>
      )}
    </div>
  );
}

/* ------------------------------------------------------------------ */
/* Componente                                                          */
/* ------------------------------------------------------------------ */

export default function Reportes() {
  const [filtros, setFiltros] = useState(FILTROS_INICIALES);
  const [textoBusqueda, setTextoBusqueda] = useState("");
  const [respuesta, setRespuesta] = useState({
    clave: null,
    datos: null,
    error: null,
  });
  const [reintento, setReintento] = useState(0);
  const [enfermeras, setEnfermeras] = useState([]);
  const [salas, setSalas] = useState([]);

  // Identifica "qué consulta es la actual". Si la última respuesta guardada
  // no corresponde a esta clave, significa que todavía está cargando.
  const clave = `${JSON.stringify(filtros)}|${reintento}`;
  const cargando = respuesta.clave !== clave;
  const datos = respuesta.datos;
  const error = respuesta.error;

  // Opciones de los dropdowns, tomadas de los servicios existentes:
  // enfermeras -> /api/enfermeras (IDs reales); salas -> las de los pacientes.
  useEffect(() => {
    const control = new AbortController();

    obtenerEnfermeras({ signal: control.signal })
      .then(setEnfermeras)
      .catch(() => {});

    obtenerPacientes({ signal: control.signal })
      .then((lista) => {
        const unicas = [...new Set(lista.map((p) => p.sala).filter(Boolean))];
        setSalas(unicas.sort((x, y) => String(x).localeCompare(String(y), "es")));
      })
      .catch(() => {});

    return () => control.abort();
  }, []);

  // Debounce del buscador: solo consulta 400 ms después de dejar de escribir
  useEffect(() => {
    const t = setTimeout(() => {
      setFiltros((prev) => ({ ...prev, busqueda: textoBusqueda }));
    }, 400);
    return () => clearTimeout(t);
  }, [textoBusqueda]);

  // Cada cambio de filtros vuelve a consultar
  useEffect(() => {
    const control = new AbortController();

    obtenerReporte(filtros, control.signal)
      .then((resultado) => {
        if (!control.signal.aborted) {
          setRespuesta({ clave, datos: resultado, error: null });
        }
      })
      .catch(() => {
        if (!control.signal.aborted) {
          setRespuesta({
            clave,
            datos: null,
            error: "No se pudo cargar el reporte.",
          });
        }
      });

    return () => control.abort();
  }, [filtros, clave]);

  function cambiarFiltro(campo, valor) {
    setFiltros((prev) => ({ ...prev, [campo]: valor }));
  }

  function limpiarFiltros() {
    setTextoBusqueda("");
    setFiltros(FILTROS_INICIALES);
  }

  const vacio = datos && datos.resumen.total_sesiones === 0;

  return (
    <section className="reportes">
      <header className="reportes__encabezado">
        <div>
          <h1>Reportes y Auditoría de Venoclisis</h1>
          <p className="reportes__subtitulo">
            Análisis histórico de sesiones de infusión, tiempos de respuesta y
            registro de alertas clínicas.
          </p>
        </div>
        <button type="button" className="btn btn--contorno" onClick={limpiarFiltros}>
          <Icono nombre="cerrar" tamano={16} />
          Limpiar filtros
        </button>
      </header>

      {/* ---------- Filtros ---------- */}
      <div className="reportes__panel reportes__filtros">
        <div className="campo">
          <label className="campo-etiqueta" htmlFor="rep-desde">
            <Icono nombre="calendario" tamano={14} />
            Rango de fechas
          </label>
          <div className="reportes__rango">
            <input
              id="rep-desde"
              type="date"
              className="input"
              value={filtros.desde}
              max={filtros.hasta}
              onChange={(e) => cambiarFiltro("desde", e.target.value)}
            />
            <span aria-hidden="true">–</span>
            <input
              aria-label="Fecha final"
              type="date"
              className="input"
              value={filtros.hasta}
              min={filtros.desde}
              onChange={(e) => cambiarFiltro("hasta", e.target.value)}
            />
          </div>
        </div>

        <div className="campo">
          <label className="campo-etiqueta" htmlFor="rep-busqueda">
            <Icono nombre="usuarios" tamano={14} />
            Paciente o ID
          </label>
          <div className="reportes__buscador">
            <Icono nombre="buscar" tamano={16} />
            <input
              id="rep-busqueda"
              type="search"
              className="input"
              placeholder="ID del paciente (número)"
              value={textoBusqueda}
              onChange={(e) => setTextoBusqueda(e.target.value)}
            />
          </div>
          {textoBusqueda.trim() !== "" && !/^\d+$/.test(textoBusqueda.trim()) && (
            <small>Por ahora el filtro solo acepta el ID numérico del paciente.</small>
          )}
        </div>

        <div className="campo">
          <label className="campo-etiqueta" htmlFor="rep-sala">
            <Icono nombre="ubicacion" tamano={14} />
            Sala o servicio
          </label>
          <select
            id="rep-sala"
            className="input"
            value={filtros.sala}
            onChange={(e) => cambiarFiltro("sala", e.target.value)}
          >
            <option value="">Todas las salas</option>
            {salas.map((s) => (
              <option key={s} value={s}>
                {s}
              </option>
            ))}
          </select>
        </div>

        <div className="campo">
          <label className="campo-etiqueta" htmlFor="rep-enfermera">
            <Icono nombre="maletin" tamano={14} />
            Enfermera
          </label>
          <select
            id="rep-enfermera"
            className="input"
            value={filtros.enfermera}
            onChange={(e) => cambiarFiltro("enfermera", e.target.value)}
          >
            <option value="">Todas las enfermeras</option>
            {enfermeras.map((e) => (
              <option key={e.id} value={e.id}>
                {e.nombre}
              </option>
            ))}
          </select>
        </div>
      </div>

      {/* ---------- Estados ---------- */}
      {cargando && (
        <div className="reportes__estado" role="status">
          Cargando reporte…
        </div>
      )}

      {!cargando && error && (
        <div className="reportes__estado reportes__estado--error" role="alert">
          <p>{error}</p>
          <button
            type="button"
            className="btn btn--primario"
            onClick={() => setReintento((n) => n + 1)}
          >
            Reintentar
          </button>
        </div>
      )}

      {!cargando && !error && vacio && (
        <div className="reportes__estado">
          No hay sesiones para los filtros seleccionados. Prueba con otro rango
          de fechas.
        </div>
      )}

      {/* ---------- Contenido ---------- */}
      {!cargando && !error && datos && !vacio && (
        <>
          <div className="reportes__kpis">
            <article className="reportes__panel kpi">
              <div className="kpi__cabecera">
                <h3 className="kpi__label">Total de sesiones</h3>
                <span className="kpi__icono kpi__icono--primario">
                  <Icono nombre="gota" tamano={20} />
                </span>
              </div>
              <p className="kpi__valor">
                {formatearNumero(datos.resumen.total_sesiones)}
                <span className="kpi__unidad">sesiones</span>
              </p>
            </article>

            <article className="reportes__panel kpi kpi--normal">
              <div className="kpi__cabecera">
                <h3 className="kpi__label">Sesiones completadas</h3>
                <span className="kpi__icono kpi__icono--normal">
                  <Icono nombre="verificado" tamano={20} />
                </span>
              </div>
              <p className="kpi__valor">
                {formatearNumero(datos.resumen.sesiones_completadas)}
                <span className="kpi__unidad">
                  {formatearPorcentaje(datos.resumen.porcentaje_completadas)}
                </span>
              </p>
            </article>

            <article className="reportes__panel kpi kpi--alerta">
              <div className="kpi__cabecera">
                <h3 className="kpi__label">Sesiones con alertas</h3>
                <span className="kpi__icono kpi__icono--alerta">
                  <Icono nombre="advertencia" tamano={20} />
                </span>
              </div>
              <p className="kpi__valor">
                {formatearNumero(datos.resumen.sesiones_con_alertas)}
                <span className="kpi__unidad">sesiones</span>
              </p>
            </article>

            <article className="reportes__panel kpi kpi--respuesta">
              <div className="kpi__cabecera">
                <h3 className="kpi__label">Tiempo promedio de respuesta</h3>
                <span className="kpi__icono kpi__icono--respuesta">
                  <Icono nombre="reloj" tamano={20} />
                </span>
              </div>
              <p className="kpi__valor">
                {formatearMinutos(datos.resumen.tiempo_promedio_respuesta_seg)}
                {datos.resumen.tiempo_promedio_respuesta_seg !== null && (
                  <span className="kpi__unidad">minutos</span>
                )}
              </p>
            </article>
          </div>

          {/* ---------- Desglose por categoría ---------- */}
          <div className="reportes__panel desglose">
            <h2 className="desglose__titulo">
              <Icono nombre="senal" tamano={20} />
              Desglose de alertas por categoría
            </h2>

            {datos.categorias.length === 0 ? (
              <p className="texto-secundario">Sin alertas en este rango.</p>
            ) : (
              <>
                <div
                  className="desglose__barra"
                  role="img"
                  aria-label="Distribución de alertas por categoría"
                >
                  {datos.categorias.map((c) => (
                    <span
                      key={c.tipo}
                      className={`desglose__tramo desglose__tramo--${CLASE_CATEGORIA[c.tipo]}`}
                      style={{ width: `${c.porcentaje ?? 0}%` }}
                    />
                  ))}
                </div>

                <table className="desglose__tabla">
                  <thead>
                    <tr>
                      <th scope="col">Tipo</th>
                      <th scope="col" className="num">Cantidad</th>
                      <th scope="col" className="num">%</th>
                      <th scope="col" className="num">Promedio de resolución</th>
                    </tr>
                  </thead>
                  <tbody>
                    {datos.categorias.map((c) => (
                      <tr key={c.tipo}>
                        <td>
                          <span
                            className={`etiqueta etiqueta--${CLASE_CATEGORIA[c.tipo]}`}
                          >
                            {c.tipo}
                          </span>
                        </td>
                        <td className="num">{formatearNumero(c.cantidad)}</td>
                        <td className="num">{formatearPorcentaje(c.porcentaje)}</td>
                        <td className="num">
                          {formatearDuracion(c.promedio_resolucion_seg)}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </>
            )}
          </div>
        </>
      )}

      <TablaSesiones filtros={filtros} />
    </section>
  );
}