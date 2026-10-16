import { useEffect, useState } from 'react';
import TarjetaPaciente from "../components/TarjetaPaciente";
import Icono from "../components/Icono";
import echo from '../echo';
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

// Cálculo del estado de la tarjeta según las reglas de la actividad
function calcularEstadoTarjeta(gotasPorMin, volRestante) {
  if (gotasPorMin < 15 || gotasPorMin > 80 || volRestante < 50) {
    return { estado: "alerta", textoEstado: "ALERTA DE INFUSIÓN" };
  }
  if (gotasPorMin < 20 || gotasPorMin > 60 || volRestante < 100) {
    return { estado: "atencion", textoEstado: "ATENCIÓN REQUERIDA" };
  }
  return { estado: "normal", textoEstado: "GOTEO REGULAR" };
}

export default function Dashboard() {
  // Inicializamos en vacío para cumplir con que solo aparezcan si el servidor manda datos de camas con pacientes
  const [pacientes, setPacientes] = useState([
    {
      id: 1,
      cama: "cama-01",
      pacienteId: "cama-01",
      nombre: "Valeria Pérez",
      edad: 22,
      idPaciente: "Habitación 405-A • ID: 9104",
      gotasPorMin: 45,
      estadoRitmo: "Constante",
      flujoActual: 45,
      metaFlujo: 60,
      porcentajeVolumen: 60,
      volRestante: 300,
      volTotal: 500,
      tiempoRestante: "Restan est. 2h 00m",
      estado: "normal",
      textoEstado: "GOTEO REGULAR",
      nombreSolucion: "Solución Salina 0.9%",
      tipoBajante: "MICRO (60 GTT/ML)"
    }
  ]);

  const [sala, setSala] = useState(SALAS[0].id);
  const [busqueda, setBusqueda] = useState("");
  const [filtro, setFiltro] = useState("todos");

  useEffect(() => {
    console.log("¡El componente Dashboard se montó correctamente!");

    const channel = echo.channel('pacientes');

    channel.subscription.bind('pusher:subscription_succeeded', () => {
      console.log("✅ ¡Suscripción exitosa y confirmada al canal 'pacientes'!");
    });

    channel.listen('.nueva.lectura', (e) => {
      console.log("🚀 ¡EVENTO RECIBIDO DEL SIMULADOR:", e);

      // Extraer la lista de paquetes del evento de Reverb
      const rawData = e.datapacket || e.data || e;
      const packetsList = Array.isArray(rawData) ? rawData : [rawData];

      setPacientes((prevPacientes) => {
        let updatedPacientes = [...prevPacientes];

        packetsList.forEach((packet) => {
          // El simulador manda "pacienteId" (ej: "cama-01")
          const camaRecibida = packet.pacienteId || packet.cama;
          if (!camaRecibida) return;

          // Buscar si ya existe la tarjeta con esa cama o id
          const index = updatedPacientes.findIndex(
            (p) => p.cama === camaRecibida || p.pacienteId === camaRecibida || p.idPaciente?.includes(camaRecibida)
          );

          const gotas = Number(packet.gotasPorMin) || 0;
          const volumen = Number(packet.volRestante) || 0;
          const tiempoMinutos = Number(packet.tiempoRestante) || 0;

          // Formatear el tiempo restante a horas y minutos legibles
          const horas = Math.floor(tiempoMinutos / 60);
          const mins = tiempoMinutos % 60;
          const textoTiempo = `Restan est. ${horas}h ${mins}m`;

          // Calcular estado de alerta según los umbrales de infusión
          let estadoCalculado = "normal";
          let textoEstadoCalculado = "GOTEO REGULAR";
          if (gotas < 15 || gotas > 80 || volumen < 50) {
            estadoCalculado = "alerta";
            textoEstadoCalculado = "ALERTA DE INFUSIÓN";
          }

          if (index !== -1) {
            // Actualizar la tarjeta existente con los nuevos valores del simulador
            updatedPacientes[index] = {
              ...updatedPacientes[index],
              gotasPorMin: gotas,
              flujoActual: gotas,
              volRestante: volumen,
              porcentajeVolumen: Math.round((volumen / 500) * 100),
              tiempoRestante: textoTiempo,
              estado: estadoCalculado,
              textoEstado: textoEstadoCalculado,
            };
          } else {
            // Si la tarjeta no existe, crearla dinámicamente para la cama recibida
            updatedPacientes.push({
              id: Date.now() + Math.random(),
              cama: camaRecibida,
              pacienteId: camaRecibida,
              nombre: `Paciente Cama ${camaRecibida}`,
              edad: 28,
              idPaciente: `Habitación • ID: ${camaRecibida}`,
              gotasPorMin: gotas,
              estadoRitmo: packet.modo || "Constante",
              flujoActual: gotas,
              metaFlujo: 60,
              porcentajeVolumen: Math.round((volumen / 500) * 100),
              volRestante: volumen,
              volTotal: 500,
              tiempoRestante: textoTiempo,
              estado: estadoCalculado,
              textoEstado: textoEstadoCalculado,
              nombreSolucion: "Solución Salina 0.9%",
              tipoBajante: "MICRO (60 GTT/ML)"
            });
          }
        });

        return updatedPacientes;
      });
    });

    return () => {
      echo.leave('pacientes');
    };
  }, []);

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
              <TarjetaPaciente key={paciente.id || paciente.cama} {...paciente} />
            ))}
          </div>
        )}
      </div>
    </div>
  );
}