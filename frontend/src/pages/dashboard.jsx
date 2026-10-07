import React, { useState } from 'react';
import TarjetaPaciente from '../components/TarjetaPaciente';
import './dashboard.css';
import AlertaBanner from '../components/AlertaBanner';

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
      nombreSolucion:"Solución Lactato 0.9%",
      tipoBajante:"MICRO (10 GTT/ML)",
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
      nombreSolucion:"Solución Salina 0.9%",
      tipoBajante:"MACRO (10 GTT/ML)",
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
      nombreSolucion:"Lactato Glucosado 5%",
      tipoBajante:"MICRO (10 GTT/ML)",
    }
  ]);

    // Datos fijos de prueba para la tarjeta #38
  // Se reemplazarnn por las alertas que lleguen del backend via WebSocket
  const [alertas, setAlertas] = useState([
    {
      id: 1,
      paciente: "Anna Garcia",
      cama: "cama-06",
      tipo: "FIN_BOLSA",
      hora: "10:42"
    },
    {
      id: 2,
      paciente: "Carlos Martinez",
      cama: "cama-03",
      tipo: "GOTEO_LENTO",
      hora: "10:15"
    }
  ]);

  const atenderAlerta = (id) =>
    setAlertas((previas) => previas.filter((alerta) => alerta.id !== id));

  return (
    <div className="contenedor-dashboard-vista">
      
           {alertas.length > 0 && (
        <div className="seccion-alertas-dashboard">
          {alertas.map((alerta) => (
            <AlertaBanner
              key={alerta.id}
              paciente={alerta.paciente}
              cama={alerta.cama}
              tipo={alerta.tipo}
              hora={alerta.hora}
              onAtender={() => atenderAlerta(alerta.id)}
            />
          ))}
        </div>
      )}
      <div className="seccion-contenido-dashboard">
        {pacientes.length === 0 ? (
          <div className="estado-vacio-dashboard">
            <h3>Sin pacientes asignados</h3>
          </div>
        ) : (
          <div className="grid-tarjetas-pacientes">
            {pacientes.map((paciente) => (
              <TarjetaPaciente
                key={paciente.id}
                {...paciente}
              />
            ))}
          </div>
        )}
      </div>
    </div>
  );
}