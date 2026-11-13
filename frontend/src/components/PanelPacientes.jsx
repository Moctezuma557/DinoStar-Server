import { useState, useEffect, useRef } from "react";
import { crearPaciente, actualizarPaciente, darDeBajaPaciente } from "../services/pacientes";
import { useEnfermeras } from "../hooks/useEnfermeras";
import estilos from "./DialogoAltas.module.css";

export default function PanelPacientes({
  onCerrar,
  onPacienteCreado,
  onPacienteEditado,
  onPacienteDadoDeBaja,
  pacientes = [],
}) {
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
  const [exito, setExito] = useState(null);
  const refExito = useRef(null);

  // Lleva el mensaje de éxito a la vista (el cuerpo del modal tiene scroll)
  useEffect(() => {
    if (exito && refExito.current) {
      refExito.current.scrollIntoView({ behavior: "smooth", block: "nearest" });
    }
  }, [exito]);

  const { enfermeras } = useEnfermeras();

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
    setExito(null);
  };

  // `p` viene en formato adaptado: cama, solucion, volTotal, modoGoteo, enfermeraId
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
    setExito(null);
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
    setExito(null);

    try {
      const datos = armarDatos();
      const nombreGuardado = nombre.trim();
      let mensaje = "";

      if (modo === "crear") {
        const creado = await crearPaciente(datos);
        if (onPacienteCreado) onPacienteCreado(creado);
        mensaje = `Paciente ${nombreGuardado} registrado correctamente en la cama ${numeroCama}.`;
      } else if (modo === "editar" && idPaciente) {
        const actualizado = await actualizarPaciente(idPaciente, datos);
        if (onPacienteEditado) onPacienteEditado(actualizado);
        mensaje = `Paciente ${nombreGuardado} actualizado correctamente.`;
      }

      // El modal se queda abierto para que el usuario vea la confirmación.
      limpiarFormulario();
      setExito(mensaje);
    } catch (err) {
      setError(err.message || "Ocurrió un error");
    } finally {
      setCargando(false);
    }
  };

  const handleDarDeBaja = async () => {
    if (!idPaciente) return;

    if (!confirm("¿Estás seguro de dar de baja a este paciente?.")) {
      return;
    }

    setCargando(true);
    setError(null);
    setExito(null);

    try {
      const nombreBaja = nombre.trim();
      await darDeBajaPaciente(idPaciente, true);
      if (onPacienteDadoDeBaja) onPacienteDadoDeBaja(idPaciente);
      limpiarFormulario();
      setExito(`Paciente ${nombreBaja} fue dado de baja.`);
    } catch (err) {
      setError(`Error al dar de baja: ${err.message || "Ocurrió un error"}`);
    } finally {
      setCargando(false);
    }
  };

  const enEdicion = modo === "editar" && idPaciente;

  return (
    <>
      <div className={estilos.cuerpo}>
        <form
          onSubmit={handleSubmit}
          id="form-alta-paciente"
          className={estilos.formularioColumna}
        >
          {enEdicion ? (
            <div className={estilos.avisoRequerido}>
              <strong>Editar paciente</strong> — Solo se pueden editar nombre, cama y sala.
            </div>
          ) : (
            <div className={estilos.avisoRequerido}>
              <strong>Alta de pacientes</strong> — Campos marcados con * son
              obligatorios
            </div>
          )}

          {error && <div className={estilos.alertaError}>{error}</div>}
          {exito && (
            <div ref={refExito} className={estilos.mensajeExito} role="status">
              {exito}
            </div>
          )}

          <div className={estilos.campo}>
            <label className={estilos.campo} htmlFor="paciente-select">
              <span className="campo-etiqueta">Paciente</span>
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
                <small className={estilos.campoAyuda}>Rango permitido: 1 a 5,000 ml.</small>
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
          <div className={estilos.avisoSeguridad}>
            <span>
              <strong>Sesiones activas:</strong> Al registrar un paciente nuevo se crea tarjeta con sesion activa.
            </span>
          </div>
        </form>

      </div>

      <footer className={estilos.pie}>
        <span className={estilos.pieInfo}>Conexión Segura DinoStar Gateway</span>
        <div className={estilos.pieAcciones}>
          {enEdicion && (
            <button type="button" className="btn btn--contorno" onClick={handleDarDeBaja} disabled={cargando}>
              Dar de Baja
            </button>
          )}
          <button type="button" className="btn btn--contorno" onClick={onCerrar}>
            Cerrar
          </button>
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
        </div>
      </footer>
    </>
  );
}
