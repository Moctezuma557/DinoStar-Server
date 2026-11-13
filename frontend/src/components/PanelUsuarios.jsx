import { useState, useEffect, useCallback, useRef } from "react";
import Icono from "./Icono";
import {
  obtenerUsuarios,
  crearUsuario,
  actualizarUsuario,
  darDeBajaUsuario,
} from "../services/usuarios";
import estilos from "./DialogoAltas.module.css";

const TURNOS = [
  { valor: "MATUTINO", etiqueta: "MATUTINO" },
  { valor: "VESPERTINO", etiqueta: "VESPERTINO" },
  { valor: "NOCTURNO", etiqueta: "NOCTURNO" },
];

export default function PanelUsuarios({ onCerrar }) {
  // ----- Lista -----
  const [usuarios, setUsuarios] = useState([]);
  const [cargandoLista, setCargandoLista] = useState(true);

  // ----- Formulario -----
  const [modo, setModo] = useState("crear");
  const [idUsuario, setIdUsuario] = useState(null);

  const [nombre, setNombre] = useState("");
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [mostrarPassword, setMostrarPassword] = useState(false);
  const [rol, setRol] = useState("ENFERMERA");
  const [turno, setTurno] = useState("MATUTINO");

  const [guardando, setGuardando] = useState(false);
  const [error, setError] = useState(null);
  const [exito, setExito] = useState(null);
  const refExito = useRef(null);

  const cargarUsuarios = useCallback(async (signal) => {
    try {
      const lista = await obtenerUsuarios({ signal });
      setUsuarios(lista);
    } catch (err) {
      if (err.name === "AbortError") return;
      setError(err.message || "No se pudo cargar la lista de usuarios");
    } finally {
      if (!signal?.aborted) setCargandoLista(false);
    }
  }, []);

  useEffect(() => {
    const control = new AbortController();
    cargarUsuarios(control.signal);
    return () => control.abort();
  }, [cargarUsuarios]);

  // Lleva el mensaje de éxito a la vista (el cuerpo del modal tiene scroll)
  useEffect(() => {
    if (exito && refExito.current) {
      refExito.current.scrollIntoView({ behavior: "smooth", block: "nearest" });
    }
  }, [exito]);

  const limpiarFormulario = () => {
    setModo("crear");
    setIdUsuario(null);
    setNombre("");
    setEmail("");
    setPassword("");
    setMostrarPassword(false);
    setRol("ENFERMERA");
    setTurno("MATUTINO");
    setError(null);
    setExito(null);
  };

  const cargarFormulario = (u) => {
    setModo("editar");
    setIdUsuario(u.id);
    setNombre(u.nombre || "");
    setEmail(u.email || "");
    setPassword("");
    setMostrarPassword(false);
    setRol(u.rol || "ENFERMERA");
    setTurno(u.turno ?? "MATUTINO");
    setError(null);
    setExito(null);
  };

  const enEdicion = modo === "editar" && idUsuario !== null;
  const esAdmin = rol === "ADMIN";

  const handleSubmit = async (e) => {
    e.preventDefault();
    setGuardando(true);
    setError(null);
    setExito(null);

    try {
      let mensaje = "";
      if (enEdicion) {
        const datos = { nombre: nombre.trim(), email: email.trim() };
        if (password) datos.password = password;
        if (!esAdmin) datos.turno = turno;

        await actualizarUsuario(idUsuario, datos);
        mensaje = `Usuario «${nombre.trim()}» actualizado correctamente.`;
      } else {
        await crearUsuario({
          nombre: nombre.trim(),
          email: email.trim(),
          password,
          rol: "ENFERMERA",
          turno,
          activo: true,
        });
        mensaje = `Enfermera «${nombre.trim()}» creada correctamente.`;
      }

      limpiarFormulario();
      setExito(mensaje);
      await cargarUsuarios();
    } catch (err) {
      console.error("[Usuarios] error al guardar:", err);
      setError(err.message || "Ocurrió un error");
    } finally {
      setGuardando(false);
    }
  };

  const handleDarDeBaja = async () => {
    if (!idUsuario) return;
    const usuarioActual = usuarios.find((u) => u.id === idUsuario);
    const nombreBaja = usuarioActual ? usuarioActual.nombre : "este usuario";

    if (!confirm(`¿Estás seguro de dar de baja a ${nombreBaja}?.`)) return;

    setGuardando(true);
    setError(null);
    setExito(null);
    try {
      await darDeBajaUsuario(idUsuario);
      limpiarFormulario();
      setExito(`Usuario ${nombreBaja} fue dado de baja.`);
      await cargarUsuarios();
    } catch (err) {
      console.error("[Usuarios] error al dar de baja:", err);
      setError(err.message || "No se pudo dar de baja al usuario");
    } finally {
      setGuardando(false);
    }
  };

  return (
    <>
      <div className={estilos.cuerpo}>
        <form
          id="form-usuario"
          onSubmit={handleSubmit}
          className={estilos.formularioColumna}
        >
          {enEdicion ? (
            <div className={estilos.avisoRequerido}>
              <strong>Editar usuario</strong> — Modificando usuario.
            </div>
          ) : (
            <div className={estilos.avisoRequerido}>
              <strong>Alta de usuario</strong> — Campos marcados con * son obligatorios
            </div>
          )}

          {error && <div className={estilos.alertaError}>{error}</div>}
          {exito && (
            <div ref={refExito} className={estilos.mensajeExito} role="status">
              {exito}
            </div>
          )}

          <div className={estilos.campo}>
            <label className={estilos.campo} htmlFor="usuario-select">
              <span className="campo-etiqueta">Personal</span>
              <select
                id="usuario-select"
                className="input"
                value={idUsuario ?? ""}
                onChange={(e) => {
                  const u = usuarios.find((x) => String(x.id) === e.target.value);
                  if (u) cargarFormulario(u);
                  else limpiarFormulario();
                }}
              >
                <option value="">Editar Enfermera</option>
                {usuarios.map((u) => (
                  <option key={u.id} value={u.id}>
                    {u.nombre} — {u.rol} ({u.activo ? "Activo" : "Inactivo"})
                  </option>
                ))}
              </select>
            </label>
          </div>

          <div className={estilos.gridDoble}>
            <div className={estilos.campo}>
              <label className={estilos.campo} htmlFor="usuario-nombre">
                <span className="campo-etiqueta">Nombre completo del usuario *</span>
                <input
                  id="usuario-nombre"
                  type="text"
                  className="input"
                  required
                  autoFocus
                  maxLength={150}
                  placeholder="Ej. Lic. Mariana Valenzuela Castro"
                  value={nombre}
                  onChange={(e) => setNombre(e.target.value)}
                />
                <small className={estilos.campoAyuda}>Máximo 150 caracteres.</small>
              </label>
            </div>

            <div className={estilos.campo}>
              <label className={estilos.campo} htmlFor="usuario-email">
                <span className="campo-etiqueta">Correo electrónico corporativo *</span>
                <input
                  id="usuario-email"
                  type="email"
                  className="input"
                  required
                  maxLength={255}
                  placeholder="nombre.apellido@hospital-dinostar.med"
                  value={email}
                  onChange={(e) => setEmail(e.target.value)}
                />
              </label>
            </div>
          </div>

          <div className={estilos.gridDoble}>
            <div className={estilos.campo}>
              <label className={estilos.campo} htmlFor="usuario-password">
                <span className="campo-etiqueta">
                  {enEdicion ? "Nueva contraseña (opcional)" : "Contraseña segura *"}
                </span>
                <span className={estilos.campoPassword}>
                  <input
                    id="usuario-password"
                    type={mostrarPassword ? "text" : "password"}
                    className="input"
                    required={!enEdicion}
                    minLength={8}
                    autoComplete="new-password"
                    placeholder={enEdicion ? "Dejar vacío para conservarla" : "Mínimo 8 caracteres"}
                    value={password}
                    onChange={(e) => setPassword(e.target.value)}
                  />
                </span>
              </label>
            </div>

            <div className={estilos.campo}>
              <label className={estilos.campo} htmlFor="usuario-rol">
                <span className="campo-etiqueta">Rol institucional *</span>
                <select
                  id="usuario-rol"
                  className="input"
                  required
                  value={rol}
                  onChange={(e) => setRol(e.target.value)}
                  disabled={enEdicion}
                >
                  <option value="ENFERMERA">ENFERMERA</option>
                  <option value="ADMIN" disabled>
                    ADMIN (requiere sesión de administrador)
                  </option>
                </select>
                <small className={estilos.campoAyuda}>
                  {enEdicion
                    ? "El rol no se puede cambiar desde aquí."
                    : "Solo un administrador autenticado puede crear administradores."}
                </small>
              </label>
            </div>
          </div>

          {!esAdmin && (
            <div className={estilos.gridDoble}>
              <div className={estilos.campo}>
                <label className={estilos.campo} htmlFor="usuario-turno">
                  <span className="campo-etiqueta">Turno *</span>
                  <select
                    id="usuario-turno"
                    className="input"
                    required
                    value={turno}
                    onChange={(e) => setTurno(e.target.value)}
                  >
                    {TURNOS.map((t) => (
                      <option key={t.valor} value={t.valor}>
                        {t.etiqueta}
                      </option>
                    ))}
                  </select>
                  <small className={estilos.campoAyuda}>Requerido para perfil enfermería.</small>
                </label>
              </div>
            </div>
          )}
        </form>

        <div className={estilos.avisoSeguridad}>
          <span>
            <strong>Permisos de Enfermera:</strong> acceso a monitor de venoclisis, registro de
            infusiones y atención de alarmas. Sin privilegios de configuración global.
          </span>
        </div>
      </div>

      <footer className={estilos.pie}>
        <span className={estilos.pieInfo}> Conexión Segura DinoStar Gateway</span>
        <div className={estilos.pieAcciones}>
          {enEdicion && (
            <button
              type="button"
              className="btn btn--contorno"
              onClick={handleDarDeBaja}
              disabled={guardando}
            >
              Dar de Baja
            </button>
          )}
          <button type="button" className="btn btn--contorno" onClick={onCerrar}>
            Cerrar
          </button>
          <button
            type="submit"
            form="form-usuario"
            className="btn btn--primario"
            disabled={guardando}
          >
            {guardando
              ? enEdicion
                ? "Actualizando..."
                : "Guardando..."
              : enEdicion
                ? "Actualizar Usuario"
                : "Guardar Usuario"}
          </button>
        </div>
      </footer>
    </>
  );
}