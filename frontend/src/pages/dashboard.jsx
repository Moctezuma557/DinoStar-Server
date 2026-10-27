import { useEffect, useState } from 'react';
import TarjetaPaciente from "../components/TarjetaPaciente";
import Icono from "../components/Icono";
import { useDashboardContext } from "../layouts/DashboardLayout";
import "./dashboard.css";

const SALAS = [
  { id: "icu-oeste", nombre: "ICU – Ala Oeste (Adultos & Agudos)" },
  { id: "pediatria-b", nombre: "Unidad Pediátrica B" },
  { id: "medicina-interna", nombre: "Medicina Interna – Ala Norte" },
];

function textoBuscable(paciente) {
  return [paciente.nombre, paciente.cama, paciente.idPaciente]
    .filter(Boolean)
    .join(" ")
    .toLowerCase();
}

export default function Dashboard() {
  // Consumimos los pacientes globales y el trigger de alerta directamente del layout
  const { pacientes, triggerAlerta } = useDashboardContext();

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
            placeholder="Buscar pacientes o número de sala..."
            value={busqueda}
            onChange={(evento) => setBusqueda(evento.target.value)}
            aria-label="Buscar pacientes o número de sala"
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

        <button type="button" className="boton-registrar-paciente">
          <Icono nombre="agregarUsuario" tamano={16} />
          Registrar nuevo paciente
        </button>
      </div>

      <div className="seccion-contenido-dashboard">
        {pacientesVisibles.length === 0 ? (
          <div className="estado-vacio-dashboard">
            <h3>Ningún paciente coincide con los filtros actuales</h3>
          </div>
        ) : (
          <div className="grid-tarjetas-pacientes">
            {pacientesVisibles.map((paciente) => (
              <TarjetaPaciente key={paciente.id || paciente.cama} {...paciente} />
            ))}
          </div>
        )}
      </div>
    </div>
  );
}