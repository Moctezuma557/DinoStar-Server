import { useState, useEffect } from "react";
import Icono from "./Icono";
import PanelPacientes from "./PanelPacientes";
import PanelSesiones from "./PanelSesiones";
import PanelUsuarios from "./PanelUsuarios";
import estilos from "./DialogoAltas.module.css";

const TABS = [
  { id: "pacientes", etiqueta: "Pacientes", badge: "Alta", icono: "agregarUsuario" },
  { id: "sesiones", etiqueta: "Sesiones", badge: "Monitoreo", icono: "gota" },
  { id: "usuarios", etiqueta: "Usuarios", badge: "Personal", icono: "maletin" },
];

/**
 * Contenedor del diálogo "Opciones de alta"
 */
export default function OpcionesAltaModal({
  onCerrar,
  onPacienteCreado,
  onPacienteEditado,
  onPacienteDadoDeBaja,
  onSesionCambiada,
  pacientes = [],
}) {
  const [tabActivo, setTabActivo] = useState("pacientes");

  // Cerrar con Escape
  useEffect(() => {
    function alPresionarTecla(evento) {
      if (evento.key === "Escape") onCerrar();
    }
    document.addEventListener("keydown", alPresionarTecla);
    return () => document.removeEventListener("keydown", alPresionarTecla);
  }, [onCerrar]);

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
              <h2 id="titulo-opciones-alta">Opciones de Alta y Registro Clínico</h2>
              <span className={estilos.badgeLinea}>EN LÍNEA</span>
            </div>
            <p className="texto-secundario">
              Gestión estructurada de admisiones, sesiones de venoclisis y credenciales de
              enfermería
            </p>
          </div>
          <button
            type="button"
            className={estilos.botonCerrar}
            onClick={onCerrar}
            aria-label="Cerrar modal"
          >
            <Icono nombre="cerrar" tamano={18} aria-hidden="true" />
          </button>
        </header>

        <div className={estilos.tabs} role="tablist">
          {TABS.map((tab) => (
            <button
              key={tab.id}
              type="button"
              role="tab"
              aria-selected={tabActivo === tab.id}
              className={`${estilos.tab} ${tabActivo === tab.id ? estilos.tabActiva : ""}`}
              onClick={() => setTabActivo(tab.id)}
            >
              <Icono nombre={tab.icono} tamano={16} />
              {tab.etiqueta} <span className={estilos.tabBadge}>{tab.badge}</span>
            </button>
          ))}
        </div>

        {tabActivo === "pacientes" && (
          <PanelPacientes
            onCerrar={onCerrar}
            onPacienteCreado={onPacienteCreado}
            onPacienteEditado={onPacienteEditado}
            onPacienteDadoDeBaja={onPacienteDadoDeBaja}
            pacientes={pacientes}
          />
        )}

        {tabActivo === "sesiones" && (
          <PanelSesiones onCerrar={onCerrar} onSesionCambiada={onSesionCambiada} />
        )}

        {tabActivo === "usuarios" && <PanelUsuarios onCerrar={onCerrar} />}
      </section>
    </div>
  );
}
