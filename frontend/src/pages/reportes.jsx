import { useEffect, useState } from "react";
import Icono from "../components/Icono";
import { obtenerReporte } from "../services/reportes";
import { obtenerEnfermeras } from "../services/enfermeras";
import { obtenerPacientes } from "../services/pacientes";
import "./reportes.css";


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


  const clave = `${JSON.stringify(filtros)}|${reintento}`;
  const cargando = respuesta.clave !== clave;
  const datos = respuesta.datos;
  const error = respuesta.error;

 
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
    </section>
  );
}