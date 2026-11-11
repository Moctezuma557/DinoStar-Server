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

function textoBuscable(paciente) {
  return [paciente.nombre, paciente.cama, paciente.idPaciente]
    .filter(Boolean)
    .join(" ")
    .toLowerCase();
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

  const [sala, setSala] = useState(SALAS[0].id);
  const [busqueda, setBusqueda] = useState("");
  const [filtro, setFiltro] = useState("todos");

  useEffect(() => {
    if (triggerAlerta > 0) {
      setFiltro("alertas");
    }
  }, [triggerAlerta]);

  const totalAlertas = pacientes.filter((p) => p.estado === "alerta").length;

  const termino = busqueda.trim().toLowerCase();
  const pacientesVisibles = pacientes
    .filter((p) => (filtro === "alertas" ? p.estado === "alerta" : true))
    .filter((p) => termino === "" || textoBuscable(p).includes(termino));

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
            aria-label="Seleccionar sala"
          >
            {SALAS.map((opcion) => (
              <option key={opcion.id} value={opcion.id}>
                {opcion.nombre}
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
            placeholder="Buscar pacientes o numero de sala..."
            value={busqueda}
            onChange={(evento) => setBusqueda(evento.target.value)}
            aria-label="Buscar pacientes o numero de sala"
          />
        </div>

        <div className="filtro-pacientes" role="group" aria-label="Filtrar pacientes">
          <button
            type="button"
            className={`filtro-opcion ${filtro === "todos" ? "activa" : ""}`}
            onClick={() => setFiltro("todos")}
          >
            Todos los Pacientes
          </button>
          <button
            type="button"
            className={`filtro-opcion ${filtro === "alertas" ? "activa" : ""}`}
            onClick={() => setFiltro("alertas")}
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
    </div>
  );
}
