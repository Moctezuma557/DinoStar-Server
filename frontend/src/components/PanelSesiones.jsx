import { useState, useEffect, useCallback, useRef } from "react";
import Icono from "./Icono";
import { obtenerUsuarios } from "../services/usuarios";
import {
  obtenerSesionesActivas,
  crearSesion,
  finalizarSesionDePaciente,
} from "../services/sesiones";
import estilos from "./DialogoAltas.module.css";

function formatearFecha(iso) {
  if (!iso) return "—";
  const fecha = new Date(iso);
  return Number.isNaN(fecha.getTime())
    ? "—"
    : fecha.toLocaleString("es-MX", { dateStyle: "short", timeStyle: "short" });
}

export default function PanelSesiones({ onCerrar, onSesionCambiada }) {
  // ----- Datos -----
  const [enfermeras, setEnfermeras] = useState([]);
  const [sesionesActivas, setSesionesActivas] = useState([]);
  const [cargando, setCargando] = useState(true);

  // ----- Formulario de alta -----
  const [pacienteId, setPacienteId] = useState("");
  const [enfermeraId, setEnfermeraId] = useState("");
  const [volTotal, setVolTotal] = useState(500);
  const [modoGoteo, setModoGoteo] = useState("NORMAL_GOTEO");
  const [tipoSolucion, setTipoSolucion] = useState("");

  // ----- Finalizar -----
  const [pacienteFinalizar, setPacienteFinalizar] = useState("");

  const [guardando, setGuardando] = useState(false);
  const [finalizandoId, setFinalizandoId] = useState(null);
  const [error, setError] = useState(null);
  const [exito, setExito] = useState(null);

  // Los mensajes salen arriba del panel; si el usuario está más abajo,
  // lo llevamos hasta ellos para que siempre vea el resultado.
  const mensajeRef = useRef(null);
  useEffect(() => {
    if (error || exito) {
      mensajeRef.current?.scrollIntoView({ behavior: "smooth", block: "nearest" });
    }
  }, [error, exito]);

  const cargarDatos = useCallback(async (signal) => {
    const [resEnfermeras, resActivas] = await Promise.allSettled([
      obtenerUsuarios({ rol: "ENFERMERA", activo: true, signal }),
      obtenerSesionesActivas({ signal }),
    ]);

    if (signal?.aborted) return;

    const fallos = [];
    if (resEnfermeras.status === "fulfilled") setEnfermeras(resEnfermeras.value);
    else fallos.push(`enfermeras (${resEnfermeras.reason?.message})`);

    if (resActivas.status === "fulfilled") setSesionesActivas(resActivas.value);
    else fallos.push(`sesiones activas (${resActivas.reason?.message})`);

    setError(fallos.length > 0 ? `No se pudo cargar: ${fallos.join("; ")}` : null);
    setCargando(false);
  }, []);

  useEffect(() => {
    const control = new AbortController();
    cargarDatos(control.signal);
    return () => control.abort();
  }, [cargarDatos]);

  const nombreEnfermera = (id) =>
    enfermeras.find((e) => String(e.id) === String(id))?.nombre ?? `Enfermera ${id}`;

  const handleSubmit = async (e) => {
    e.preventDefault();
    setError(null);
    setExito(null);

    // Regla del backend: un paciente solo puede tener una sesión ACTIVA/PAUSADA.
    // Se avisa aquí para evitar el 409.
    if (sesionesActivas.some((s) => String(s.pacienteId) === String(pacienteId))) {
      setError("Ese paciente ya tiene una sesión en curso.");
      return;
    }

    setGuardando(true);

    try {
      await crearSesion({
        paciente_id: Number(pacienteId),
        enfermera_id: Number(enfermeraId),
        vol_total: Number(volTotal),
        modo_goteo: modoGoteo,
        tipo_solucion: tipoSolucion.trim(),
      });

      setExito("Sesión iniciada");
      setPacienteId("");
      setTipoSolucion("");
      setVolTotal(500);
      await cargarDatos();
      if (onSesionCambiada) onSesionCambiada();
    } catch (err) {
      // 409: el paciente ya tiene sesión en curso · 422: validación
      setError(err.message || "Ocurrió un error");
    } finally {
      setGuardando(false);
    }
  };

  const handleFinalizar = async (sesion) => {
    if (
      !confirm(
        `¿Finalizar la sesión de ${sesion.pacienteNombre}? Esta acción cierra la infusión y no se puede deshacer.`,
      )
    ) {
      return;
    }

    setFinalizandoId(sesion.pacienteId);
    setError(null);
    setExito(null);

    try {
      await finalizarSesionDePaciente(sesion.pacienteId, sesion.id);
      setExito(`La sesión quedó FINALIZADA.`);
      setPacienteFinalizar("");
      await cargarDatos();
      if (onSesionCambiada) onSesionCambiada();
    } catch (err) {
      setError(err.message || "No se pudo finalizar la sesión");
    } finally {
      setFinalizandoId(null);
    }
  };

  // Botón de la sección "Finalizar sesión": busca la sesión elegida y reutiliza handleFinalizar
  const handleFinalizarSeleccionada = () => {
    const sesion = sesionesActivas.find(
      (s) => String(s.pacienteId) === String(pacienteFinalizar),
    );
    if (sesion) handleFinalizar(sesion);
  };

  return (
    <>
      <div className={estilos.cuerpo}>
        <div className={estilos.seccionCabecera}>
          <span className={estilos.seccionCabeceraTitulo}>
            <Icono nombre="gota" tamano={16} />
            Configurar y alta de sesión de venoclisis
          </span>
        </div>

        <div ref={mensajeRef}>
          {error && <div className={estilos.alertaError}>{error}</div>}
          {exito && <div className={estilos.mensajeExito}>{exito}</div>}
        </div>

        <form
          id="form-sesion"
          onSubmit={handleSubmit}
          className={estilos.formularioColumna}
        >
          <div className={estilos.gridDoble}>
            <div className={estilos.campo}>
              <label className={estilos.campo} htmlFor="sesion-paciente">
                <span className="campo-etiqueta">ID del paciente *</span>
                <input
                  id="sesion-paciente"
                  type="number"
                  className="input"
                  required
                  placeholder="ID del paciente, ej. 3"
                  value={pacienteId}
                  onChange={(e) => setPacienteId(e.target.value)}
                />
                <small className={estilos.campoAyuda}>
                  Escriba el ID del paciente. Si ya tiene una sesión en curso, el
                  sistema lo avisará.
                </small>
              </label>
            </div>

            <div className={estilos.campo}>
              <label className={estilos.campo} htmlFor="sesion-enfermera">
                <span className="campo-etiqueta">Enfermera asignada *</span>
                <select
                  id="sesion-enfermera"
                  className="input"
                  required
                  value={enfermeraId}
                  onChange={(e) => setEnfermeraId(e.target.value)}
                >
                  <option value="">
                    {cargando ? "Cargando personal..." : "Seleccione personal en turno..."}
                  </option>
                  {enfermeras.map((enf) => (
                    <option key={enf.id} value={enf.id}>
                      {enf.nombre} (ID: {enf.id}) — Turno {enf.turno ?? "—"}
                    </option>
                  ))}
                </select>
              </label>
            </div>
          </div>

          <div className={estilos.gridDoble}>
            <div className={estilos.campo}>
              <label className={estilos.campo} htmlFor="sesion-volumen">
                <span className="campo-etiqueta">Volumen total (ml) *</span>
                <input
                  id="sesion-volumen"
                  type="number"
                  className="input"
                  required
                  min={1}
                  max={5000}
                  value={volTotal}
                  onChange={(e) => setVolTotal(e.target.value)}
                />
                <small className={estilos.campoAyuda}>Rango permitido: 1 a 5,000 ml.</small>
              </label>
            </div>

            <div className={estilos.campo}>
              <label className={estilos.campo} htmlFor="sesion-modo">
                <span className="campo-etiqueta">Modo de goteo *</span>
                <select
                  id="sesion-modo"
                  className="input"
                  value={modoGoteo}
                  onChange={(e) => setModoGoteo(e.target.value)}
                >
                  <option value="NORMAL_GOTEO">NORMAL_GOTEO (20 gtt/ml)</option>
                  <option value="MICRO_GOTEO">MICRO_GOTEO (60 gtt/ml)</option>
                </select>
              </label>
            </div>
          </div>

          <div className={estilos.gridDoble}>
            <div className={estilos.campo}>
              <label className={estilos.campo} htmlFor="sesion-solucion">
                <span className="campo-etiqueta">Tipo de solución *</span>
                <input
                  id="sesion-solucion"
                  type="text"
                  className="input"
                  required
                  maxLength={255}
                  placeholder="Ej. Solución Salina 0.9%"
                  value={tipoSolucion}
                  onChange={(e) => setTipoSolucion(e.target.value)}
                />
              </label>
            </div>

            <div className={estilos.campo}>
              <span className="campo-etiqueta">Estado inicial de sesión</span>
              <div className={estilos.estadoAutomatico}>
                <span className={`${estilos.badge} ${estilos.badgeActivo}`}>ACTIVA</span>
                <span className={estilos.estadoAutomaticoNota}>(Automático por protocolo)</span>
              </div>
              <small className={estilos.campoAyuda}>
                El monitoreo telemétrico iniciará automáticamente al guardar.
              </small>
            </div>
          </div>
        </form>

        {/* ----- Finalizar sesión ----- */}
        <div className={estilos.listaTitulo}>
          <span>Finalizar sesión</span>
        </div>

        <div className={estilos.campo}>
          <label className={estilos.campo} htmlFor="sesion-finalizar">
            <span className="campo-etiqueta">Sesión en curso</span>
            <div style={{ display: "flex", gap: "0.75rem", alignItems: "center" }}>
              <select
                id="sesion-finalizar"
                className="input"
                style={{ flex: 1 }}
                value={pacienteFinalizar}
                onChange={(e) => setPacienteFinalizar(e.target.value)}
              >
                <option value="">
                  {cargando
                    ? "Cargando sesiones..."
                    : sesionesActivas.length === 0
                      ? "No hay sesiones en curso"
                      : "Seleccione la sesión"}
                </option>
                {sesionesActivas.map((s) => (
                  <option key={s.pacienteId} value={s.pacienteId}>
                    {s.pacienteNombre} (ID {s.pacienteId}) — {s.solucion}
                  </option>
                ))}
              </select>
              <button
                type="button"
                className={estilos.botonFinalizar}
                onClick={handleFinalizarSeleccionada}
                disabled={!pacienteFinalizar || finalizandoId !== null}
              >
                {finalizandoId !== null ? "Finalizando..." : "Finalizar sesión"}
              </button>
            </div>
          </label>
        </div>
      </div>

      <footer className={estilos.pie}>
        <span className={estilos.pieInfo}>Conexión Segura DinoStar Gateway</span>
        <div className={estilos.pieAcciones}>
          <button type="button" className="btn btn--contorno" onClick={onCerrar}>
            Cerrar
          </button>
          <button
            type="submit"
            form="form-sesion"
            className="btn btn--primario"
            disabled={guardando}
          >
            {guardando ? "Guardando..." : "Iniciar Sesión"}
          </button>
        </div>
      </footer>
    </>
  );
}
