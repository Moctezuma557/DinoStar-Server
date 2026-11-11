import { useEffect, useState } from "react";
import TarjetaPaciente from "../components/TarjetaPaciente";
import Icono from "../components/Icono";
import { useDashboardContext } from "../layouts/DashboardLayout";
import OpcionesAltaModal from "../components/DialogoAltas";
import "./dashboard.css";

const SALAS = [
  { id: "icu-oeste", nombre: "ICU  Ala Oeste (Adultos & Agudos)" },
  { id: "pediatria-b", nombre: "Unidad Pediatrica B" },
  { id: "medicina-interna", nombre: "Medicina Interna  Ala Norte" },
];
﻿import { useEffect, useMemo, useState } from 'react';
import TarjetaPaciente from "../components/TarjetaPaciente";
import Icono from "../components/Icono";
import { useDashboardContext } from "../layouts/DashboardLayout";
import { contiene } from "../utils/texto";
import "./dashboard.css";
const TODAS_LAS_SALAS = "todas";

function textoBuscable(paciente) {
  return [paciente.nombre, paciente.cama, paciente.sala, paciente.idPaciente]
    .filter(Boolean)
    .join(" ");
}

export default function Dashboard() {
  const {
    pacientes,
    setPacientes,
    triggerAlerta,
    enfermera,
    catalogoPacientes,
    refrescarPacientes,
  } = useDashboardContext();
  const enfermeraIdReal = enfermera?.id ?? 1;
  const [isOpcionesAltaOpen, setIsOpcionesAltaOpen] = useState(false);
    cargandoPacientes,
    errorPacientes,
  } = useDashboardContext();

  const enfermeraIdReal = enfermera?.id ?? 1;  

  const [sala, setSala] = useState(TODAS_LAS_SALAS);
  const [busqueda, setBusqueda] = useState("");
  const [filtro, setFiltro] = useState("todos");

  useEffect(() => {
    if (triggerAlerta > 0) {
      setFiltro("alertas");
    }
  }, [triggerAlerta]);

  const salas = useMemo(() => {
    const unicas = new Set(
      pacientes
        .map((paciente) => paciente.sala)
        .filter((valor) => valor && String(valor).trim() !== "")
    );
    return [...unicas].sort((a, b) => a.localeCompare(b, "es"));
  }, [pacientes]);

  const totalAlertas = pacientes.filter((p) => p.estado === "alerta").length;

  const hayFiltrosActivos =
    sala !== TODAS_LAS_SALAS || busqueda.trim() !== "" || filtro !== "todos";

  const limpiarFiltros = () => {
    setSala(TODAS_LAS_SALAS);
    setBusqueda("");
    setFiltro("todos");
  };

  const pacientesVisibles = pacientes
    .filter((p) => sala === TODAS_LAS_SALAS || p.sala === sala)
    .filter((p) => (filtro === "alertas" ? p.estado === "alerta" : true))
    .filter((p) => contiene(textoBuscable(p), busqueda));

  function renderizarContenido() {
    if (cargandoPacientes) {
      return (
        <div className="estado-vacio-dashboard">
          <h3>Cargando pacientes…</h3>
        </div>
      );
    }

    if (errorPacientes) {
      return (
        <div className="estado-vacio-dashboard" role="alert">
          <h3>No se pudo cargar la lista de pacientes</h3>
          <p>Revisa la conexión con el servidor e inténtalo de nuevo.</p>
        </div>
      );
    }

    if (pacientes.length === 0) {
      return (
        <div className="estado-vacio-dashboard">
          <h3>No hay pacientes con venoclisis activa</h3>
          <p>En cuanto se registre una sesión aparecerá aquí.</p>
        </div>
      );
    }

    if (pacientesVisibles.length === 0) {
      return (
        <div className="estado-vacio-dashboard">
          <h3>Ningún paciente coincide con los filtros actuales</h3>
          <p>
            Hay {pacientes.length}{" "}
            {pacientes.length === 1 ? "paciente activo" : "pacientes activos"} en total.
          </p>
          {hayFiltrosActivos && (
            <button
              type="button"
              className="filtro-opcion"
              onClick={limpiarFiltros}
            >
              Quitar filtros
            </button>
          )}
        </div>
      );
    }

    return (
      <div className="grid-tarjetas-pacientes">
        {pacientesVisibles.map((paciente) => (
          <TarjetaPaciente
            key={paciente.id || paciente.cama}
            {...paciente}
            id={paciente.alertaId ?? paciente.id}      
            enfermeraId={enfermeraIdReal}
            onAlertaResuelta={(idAlerta) => setPacientes((prevPacientes) =>
              prevPacientes.map((p) =>
                p.id === idAlerta
                  ? { ...p, estado: "normal", tipoAlerta: null }
                  : p
              )
            )}
          />
        ))}
      </div>
    );
  }

  // Tras crear o editar, el layout vuelve a pedir la lista al servidor
  // y actualiza las tarjetas (nombre, cama, sala, paciente nuevo).
  const manejarPacienteCreado = () => {
    refrescarPacientes();
  };

  const manejarPacienteEditado = () => {
    refrescarPacientes();
  };

  // Se quita la tarjeta al instante y luego se confirma con el servidor.
  const manejarPacienteDadoDeBaja = (id) => {
    setPacientes((prev) => prev.filter((p) => p.id !== id));
    refrescarPacientes();
  };

  return (
    <div className="contenedor-dashboard-vista">
      <div className="barra-herramientas-dashboard">
        <label className="selector-sala">
          <span className="selector-sala-etiqueta">SALA:</span>
          <select
            className="selector-sala-control"
            value={sala}
            onChange={(evento) => setSala(evento.target.value)}
            aria-label="Filtrar por sala"
          >
            <option value={TODAS_LAS_SALAS}>Todas las salas</option>
            {salas.map((nombreSala) => (
              <option key={nombreSala} value={nombreSala}>
                {nombreSala}
              </option>
            ))}
          </select>
          <Icono nombre="flechaAbajo" tamano={16} />
        </label>

        <div className="buscador-dashboard">
          <Icono nombre="buscar" tamano={16} />
          <input
            type="search"
            className="buscador-dashboard-campo"
            placeholder="Buscar por nombre, cama, sala o ID…"
            value={busqueda}
            onChange={(evento) => setBusqueda(evento.target.value)}
            aria-label="Buscar por nombre, cama, sala o ID"
          />
        </div>

        <div className="filtro-pacientes" role="group" aria-label="Filtrar pacientes">
          <button
            type="button"
            className={`filtro-opcion ${filtro === "todos" ? "activa" : ""}`}
            onClick={() => setFiltro("todos")}
            aria-pressed={filtro === "todos"}
          >
            Todos los Pacientes
          </button>
          <button
            type="button"
            className={`filtro-opcion ${filtro === "alertas" ? "activa" : ""}`}
            onClick={() => setFiltro("alertas")}
            aria-pressed={filtro === "alertas"}
          >
            Solo Alertas
            {totalAlertas > 0 && <span className="filtro-badge">{totalAlertas}</span>}
          </button>
        </div>

        <button type="button" className="boton-alta" onClick={() => setIsOpcionesAltaOpen(true)}>
          <Icono nombre="agregarUsuario" tamano={16} />
          Opciones de alta
        </button>
      </div>

      {isOpcionesAltaOpen && (
        <OpcionesAltaModal
          onCerrar={() => setIsOpcionesAltaOpen(false)}
          onPacienteCreado={manejarPacienteCreado}
          onPacienteEditado={manejarPacienteEditado}
          onPacienteDadoDeBaja={manejarPacienteDadoDeBaja}
          pacientes={catalogoPacientes}
        />
      )}

      <div className="seccion-contenido-dashboard">
        {pacientesVisibles.length === 0 ? (
          <div className="estado-vacio-dashboard">
            <h3>Ningun paciente coincide con los filtros actuales</h3>
          </div>
        ) : (
          <div className="grid-tarjetas-pacientes">
            {pacientesVisibles.map((paciente) => (
              <TarjetaPaciente
                key={paciente.id || paciente.cama}
                {...paciente}
                id={paciente.alertaId ?? paciente.id}
                enfermeraId={enfermeraIdReal}
                onAlertaResuelta={(idAlerta) =>
                  setPacientes((prevPacientes) =>
                    prevPacientes.map((p) =>
                      p.id === idAlerta
                        ? { ...p, estado: "normal", tipoAlerta: null }
                        : p
                    )
                  )
                }
              />
            ))}
          </div>
        )}
      </div>
      <div className="seccion-contenido-dashboard">{renderizarContenido()}</div>
    </div>
  );
}