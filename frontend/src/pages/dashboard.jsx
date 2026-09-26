import React, { useState } from 'react';
import TarjetaPaciente from '../components/TarjetaPaciente';
import './dashboard.css';
import AlertaBanner from '../components/AlertaBanner';

export default function Dashboard() {
  const [pacientes] = useState([
    {
      id: 1,
      nombre: "Valeria Perez",
      cama: "cama-01",
      gotasPorMin: 32.5,
      volRestante: 408.5,
      tiempoRestante: 245,
      estado: "normal"
    },
    {
      id: 2,
      nombre: "Carlos Martinez",
      cama: "cama-03",
      gotasPorMin: 25.0,
      volRestante: 150.0,
      tiempoRestante: 90,
      estado: "atencion"
    },
    {
      id: 3,
      nombre: "Anna Garcia",
      cama: "cama-06",
      gotasPorMin: 10.0,
      volRestante: 35.0,
      tiempoRestante: 20,
      estado: "alerta"
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
      <div className="cabecera-dashboard">
        <div className="titulo-seccion-enfermera">
          <h1>Enf. M. Arismendi</h1>
        </div>
        <div className="info-enfermera-turno">
          <div className="texto-turno">
            <strong>En turno activo</strong>
            <span>Turno matutino • Medicina Interna</span>
          </div>
        </div>
      </div>
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
                nombre={paciente.nombre}
                cama={paciente.cama}
                gotasPorMin={paciente.gotasPorMin}
                volRestante={paciente.volRestante}
                tiempoRestante={paciente.tiempoRestante}
                estado={paciente.estado}
              />
            ))}
          </div>
        )}
      </div>
    </div>
  );
}