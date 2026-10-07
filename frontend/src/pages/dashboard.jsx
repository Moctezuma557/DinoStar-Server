import { useState } from "react";
import TarjetaPaciente from "../components/TarjetaPaciente";
import Icono from "../components/Icono";
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
  const [pacientes] = useState([
    {
      id: 1,
      nombre: "Valeria Perez",
      edad: 42,
      idPaciente: "Habitación 405-A • ID: 9104",
      solucion: "Ringer Lactato",
      tipoEquipo: "MICRO (60 GTT/ML)",
      gotasPorMin: 80,
      estadoRitmo: "Constante 1 gtt",
      flujoActual: 80,
      metaFlujo: 80,
      porcentajeVolumen: 45,
      volRestante: 225,
      volTotal: 500,
      tiempoRestante: "Restan est. 2h 45m",
      estado: "normal",
      textoEstado: "GOTEO REGULAR",
      nombreSolucion: "Solución Lactato 0.9%",
      tipoBajante: "MICRO (10 GTT/ML)",
    },
    {
      id: 2,
      nombre: "Carlos Martinez",
      edad: 38,
      idPaciente: "Habitación 403-B • ID: 8821",
      solucion: "Solución Salina 0.9%",
      tipoEquipo: "MACRO (20 GTT/ML)",
      gotasPorMin: 25,
      estadoRitmo: "Lento -5 gtt",
      flujoActual: 45,
      metaFlujo: 60,
      porcentajeVolumen: 30,
      volRestante: 150,
      volTotal: 500,
      tiempoRestante: "Restan est. 1h 30m",
      estado: "atencion",
      textoEstado: "ATENCIÓN REQUERIDA",
      nombreSolucion: "Solución Salina 0.9%",
      tipoBajante: "MACRO (10 GTT/ML)",
    },
    {
      id: 3,
      nombre: "Anna Garcia",
      edad: 55,
      idPaciente: "Habitación 406-C • ID: 7490",
      solucion: "Glucosa al 5%",
      tipoEquipo: "MICRO (60 GTT/ML)",
      gotasPorMin: 10,
      estadoRitmo: "Obstrucción detectada",
      flujoActual: 10,
      metaFlujo: 50,
      porcentajeVolumen: 7,
      volRestante: 35,
      volTotal: 500,
      tiempoRestante: "Restan est. 20m",
      estado: "alerta",
      textoEstado: "ALERTA DE INFUSIÓN",
      nombreSolucion: "Lactato Glucosado 5%",
      tipoBajante: "MICRO (10 GTT/ML)",
    },
  ]);

  const [sala, setSala] = useState(SALAS[0].id);
  const [busqueda, setBusqueda] = useState("");
  const [filtro, setFiltro] = useState("todos");

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

        <div
          className="filtro-pacientes"
          role="group"
          aria-label="Filtrar pacientes"
        >
          <button
            type="button"
            className={`filtro-opcion ${filtro === "todos" ? "activa" : ""}`}
            aria-pressed={filtro === "todos"}
            onClick={() => setFiltro("todos")}
          >
            Todos los Pacientes
          </button>
          <button
            type="button"
            className={`filtro-opcion ${filtro === "alertas" ? "activa" : ""}`}
            aria-pressed={filtro === "alertas"}
            onClick={() => setFiltro("alertas")}
          >
            Solo Alertas
            {totalAlertas > 0 && (
              <span className="filtro-badge">{totalAlertas}</span>
            )}
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
            <h3>
              {pacientes.length === 0
                ? "Sin pacientes asignados"
                : "Ningún paciente coincide con la búsqueda"}
            </h3>
          </div>
        ) : (
          <div className="grid-tarjetas-pacientes">
            {pacientesVisibles.map((paciente) => (
              <TarjetaPaciente key={paciente.id} {...paciente} />
            ))}
          </div>
        )}
      </div>
    </div>
  );
}