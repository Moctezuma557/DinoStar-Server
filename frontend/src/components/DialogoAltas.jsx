import { useState, useEffect } from "react";
import Icono from "./Icono";
import { crearPaciente, actualizarPaciente, darDeBajaPaciente } from "../services/pacientes";
import { useEnfermeras } from "../hooks/useEnfermeras";
import estilos from "./DialogoAltas.module.css";

export default function OpcionesAltaModal({
  onCerrar,
  onPacienteCreado,
  onPacienteEditado,
  onPacienteDadoDeBaja,
  pacientes = [],
}) {
  const [tabActivo, setTabActivo] = useState("pacientes");

  const [modo, setModo] = useState("crear");
  const [idPaciente, setIdPaciente] = useState(null);

  const [nombre, setNombre] = useState("");
  const [numeroCama, setNumeroCama] = useState("");
  const [sala, setSala] = useState("");
  const [volTotal, setVolTotal] = useState(500);
  const [modoGoteo, setModoGoteo] = useState("NORMAL_GOTEO");
  const [tipoSolucion, setTipoSolucion] = useState("");
  const [enfermeraId, setEnfermeraId] = useState("");

  const [cargando, setCargando] = useState(false);
  const [error, setError] = useState(null);

  const { enfermeras } = useEnfermeras();

  // Cerrar con Escape
  useEffect(() => {
    function alPresionarTecla(evento) {
      if (evento.key === "Escape") onCerrar();
    }
    document.addEventListener("keydown", alPresionarTecla);
    return () => document.removeEventListener("keydown", alPresionarTecla);
  }, [onCerrar]);

  const limpiarFormulario = () => {
    setModo("crear");
    setIdPaciente(null);
    setNombre("");
    setNumeroCama("");
    setSala("");
    setVolTotal(500);
    setModoGoteo("NORMAL_GOTEO");
    setTipoSolucion("");
    setEnfermeraId("");
    setError(null);
  };

  const cargarFormulario = (p) => {
    setModo("editar");
    setIdPaciente(p.id);
    setNombre(p.nombre || "");
    setNumeroCama(p.cama || "");
    setSala(p.sala || "");
    setVolTotal(p.volTotal || 500);
    setModoGoteo(p.modoGoteo || "NORMAL_GOTEO");
    setTipoSolucion(p.solucion === "Sin solución registrada" ? "" : p.solucion || "");
    setEnfermeraId(String(p.enfermeraId || ""));
    setError(null);
  };

  const armarDatos = () => ({
    nombre,
    numero_cama: numeroCama,
    sala,
    vol_total: Number(volTotal),
    modo_goteo: modoGoteo,
    tipo_solucion: tipoSolucion,
    enfermera_id: Number(enfermeraId),
  });

  const handleSubmit = async (e) => {
    e.preventDefault();
    setCargando(true);
    setError(null);

    try {
      const datos = armarDatos();
      console.log("[Modal] enviando:", modo, datos);

      if (modo === "crear") {
        const creado = await crearPaciente(datos);
        if (onPacienteCreado) onPacienteCreado(creado);
      } else if (modo === "editar" && idPaciente) {
        const actualizado = await actualizarPaciente(idPaciente, datos);
        if (onPacienteEditado) onPacienteEditado(actualizado);
      }

      onCerrar();
    } catch (err) {
      console.error("[Modal] error al guardar:", err);
      const mensaje = err.message || "Ocurrió un error";
      if (err.estado === 409) {
        setError(mensaje);
      } else if (err.estado === 422) {
        setError(`Error de validación: ${mensaje}`);
      } else {
        setError(`${mensaje}`);
      }
    } finally {
      setCargando(false);
    }
  };

  const handleDarDeBaja = async () => {
    if (!idPaciente) return;

    if (!confirm("¿Estás seguro de dar de baja a este paciente? Esto finalizará su sesión activa.")) {
      return;
    }

    setCargando(true);
    setError(null);

    try {
      await darDeBajaPaciente(idPaciente, true);

      if (onPacienteDadoDeBaja) onPacienteDadoDeBaja(idPaciente);
      onCerrar();
    } catch (err) {
      console.error("[Modal] error al dar de baja:", err);
      const mensaje = err.message || "Ocurrió un error";
      setError(`Error al dar de baja: ${mensaje}`);
    } finally {
      setCargando(false);
    }
  };

  return (
    <div className={estilos.overlay} onClick={onCerrar}>
      <section
        className={estilos.dialogo}
        role="dialog"
        aria-modal="true"
        aria-labelledby="titulo-opciones-alta"
        onClick={(e) => e.stopPropagation()}
      >
        <header className={estilos.encabezado}>
          <div className={estilos.encabezadoInfo}>
            <div className={estilos.tituloRow}>
              <h2 id="titulo-opciones-alta">Alta y Registro Clínico</h2>
              <span className={estilos.badgeLinea}>EN LÍNEA</span>
            </div>
            <p className="texto-secundario">
              Formulario de admisiones, sesiones de venoclisis y credenciales de enfermería
            </p>
          </div>
          <button type="button" className={estilos.botonCerrar} onClick={onCerrar} aria-label="Cerrar modal">
            <Icono nombre="cerrar" tamano={18} aria-hidden="true" />
          </button>
        </header>

        <div className={estilos.tabs} role="tablist">
          <button
            type="button"
            role="tab"
            aria-selected={tabActivo === "pacientes"}
            className={`${estilos.tab} ${tabActivo === "pacientes" ? estilos.tabActiva : ""}`}
            onClick={() => setTabActivo("pacientes")}
          >
            Pacientes <span className={estilos.tabBadge}>Alta</span>
          </button>
          <button
            type="button"
            role="tab"
            aria-selected={tabActivo === "sesiones"}
            className={`${estilos.tab} ${tabActivo === "sesiones" ? estilos.tabActiva : ""}`}
            onClick={() => setTabActivo("sesiones")}
          >
            Sesiones <span className={estilos.tabBadge}>Monitoreo</span>
          </button>
          <button
            type="button"
            role="tab"
            aria-selected={tabActivo === "usuarios"}
            className={`${estilos.tab} ${tabActivo === "usuarios" ? estilos.tabActiva : ""}`}
            onClick={() => setTabActivo("usuarios")}
          >
            Usuarios <span className={estilos.tabBadge}>Personal</span>
          </button>
        </div>

        <div className={estilos.cuerpo}>
          {tabActivo === "pacientes" && (
            <form
              onSubmit={handleSubmit}
              id="form-alta-paciente"
              style={{ display: "flex", flexDirection: "column", gap: "var(--espacio-4)" }}
            >
              {modo === "editar" && idPaciente && (
                <div className={estilos.avisoRequerido}>
                  <strong>EDITAR PACIENTE</strong> — Modificando paciente ID: {idPaciente}. Solo se pueden editar nombre, cama y sala.
                </div>
              )}
              {modo === "crear" && (
                <div className={estilos.avisoRequerido}>
                 Campos marcados con * son obligatorios
                </div>
              )}

              {error && <div className={estilos.alertaError}>{error}</div>}

              
              <div className={estilos.campo}>
                <label className={estilos.campo} htmlFor="paciente-select">
                  <span className="campo-etiqueta">Editar Paciente</span>
                  <select
                    id="paciente-select"
                    className="input"
                    value={idPaciente ?? ""}
                    onChange={(e) => {
                      const p = pacientes.find((x) => String(x.id) === e.target.value);
                      if (p) cargarFormulario(p);
                      else limpiarFormulario();
                    }}
                  >
                    <option value="">Nuevo paciente</option>
                    {pacientes.map((p) => (
                      <option key={p.id} value={p.id}>
                        {p.nombre} — {p.cama}
                      </option>
                    ))}
                  </select>
                </label>
              </div>

              <div className={estilos.gridDoble}>
                <div className={estilos.campo}>
                  <label className={estilos.campo} htmlFor="nombre-input">
                    <span className="campo-etiqueta">Nombre completo *</span>
                    <input
                      id="nombre-input"
                      type="text"
                      className="input"
                      required
                      autoFocus
                      maxLength={150}
                      placeholder="Ej. Elena Rodríguez Morales"
                      value={nombre}
                      onChange={(e) => setNombre(e.target.value)}
                    />
                  </label>
                </div>

                <div className={estilos.campo}>
                  <label className={estilos.campo} htmlFor="cama-input">
                    <span className="campo-etiqueta">Número de cama *</span>
                    <input
                      id="cama-input"
                      type="text"
                      className="input"
                      required
                      maxLength={20}
                      placeholder="Ej. cama-01"
                      value={numeroCama}
                      onChange={(e) => setNumeroCama(e.target.value)}
                    />
                  </label>
                </div>
              </div>

              <div className={estilos.gridDoble}>
                <div className={estilos.campo}>
                  <label className={estilos.campo} htmlFor="sala-input">
                    <span className="campo-etiqueta">Sala de asignación *</span>
                    <input
                      id="sala-input"
                      type="text"
                      className="input"
                      required
                      maxLength={50}
                      placeholder="Ej. Sala A"
                      value={sala}
                      onChange={(e) => setSala(e.target.value)}
                    />
                  </label>
                </div>

                <div className={estilos.campo}>
                  <label className={estilos.campo} htmlFor="vol-input">
                    <span className="campo-etiqueta">Volumen total (ml) *</span>
                    <input
                      id="vol-input"
                      type="number"
                      className="input"
                      required
                      min={1}
                      max={5000}
                      value={volTotal}
                      onChange={(e) => setVolTotal(e.target.value)}
                      disabled={modo === "editar"}
                    />
                  </label>
                </div>
              </div>

              <div className={estilos.gridDoble}>
                <div className={estilos.campo}>
                  <label className={estilos.campo} htmlFor="goteo-select">
                    <span className="campo-etiqueta">Modo de goteo *</span>
                    <select
                      id="goteo-select"
                      className="input"
                      value={modoGoteo}
                      onChange={(e) => setModoGoteo(e.target.value)}
                      disabled={modo === "editar"}
                    >
                      <option value="NORMAL_GOTEO">NORMAL_GOTEO (20 gtt/ml)</option>
                      <option value="MICRO_GOTEO">MICRO_GOTEO (60 gtt/ml)</option>
                    </select>
                  </label>
                </div>

                <div className={estilos.campo}>
                  <label className={estilos.campo} htmlFor="solucion-input">
                    <span className="campo-etiqueta">Tipo de solución *</span>
                    <input
                      id="solucion-input"
                      type="text"
                      className="input"
                      required
                      placeholder="Ej. Solución Salina 0.9% o Ringer Lactato"
                      value={tipoSolucion}
                      onChange={(e) => setTipoSolucion(e.target.value)}
                      disabled={modo === "editar"}
                    />
                  </label>
                </div>
              </div>

              <div className={estilos.campo}>
                <label className={estilos.campo} htmlFor="enfermera-select">
                  <span className="campo-etiqueta">Enfermera asignada *</span>
                  <select
                    id="enfermera-select"
                    className="input"
                    required
                    value={enfermeraId}
                    onChange={(e) => setEnfermeraId(e.target.value)}
                      disabled={modo === "editar"}
                  >
                    <option value="">Seleccione personal en turno...</option>
                    {enfermeras.map((enf) => (
                      <option key={enf.id} value={enf.id}>
                        {enf.nombre} (ID: ENF-{enf.id}) — Turno {enf.turno}
                      </option>
                    ))}
                  </select>
                </label>
              </div>
            </form>
          )}

          {tabActivo === "sesiones" && (
            <div className={estilos.panelVacio}>
              <h3>Panel de Monitoreo de Sesiones</h3>
              <p>Visualización en tiempo real de venoclisis activas.</p>
            </div>
          )}

          {tabActivo === "usuarios" && (
            <div className={estilos.panelVacio}>
              <h3>Gestión de Personal y Usuarios</h3>
              <p>Credenciales de enfermería y control de accesos.</p>
            </div>
          )}
        </div>

        <footer className={estilos.pie}>
          <div className={estilos.pieAcciones}>
            {modo === "editar" && idPaciente && tabActivo === "pacientes" && (
              <button type="button" className="btn btn--contorno" onClick={handleDarDeBaja} disabled={cargando}>
                 Dar de Baja
              </button>
            )}
            <button type="button" className="btn btn--contorno" onClick={onCerrar}>
              Cancelar
            </button>

            {tabActivo === "pacientes" && (
              <button
                type="submit"
                form="form-alta-paciente"
                className="btn btn--primario"
                disabled={cargando}
              >
                {cargando
                  ? modo === "editar"
                    ? "Actualizando..."
                    : "Guardando..."
                  : modo === "editar"
                  ? "Actualizar Paciente"
                  : "Guardar Registro"}
              </button>
            )}
          </div>
        </footer>
      </section>
    </div>
  );
}
