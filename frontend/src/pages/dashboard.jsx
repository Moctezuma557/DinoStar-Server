import React, { useState } from 'react';
import TarjetaPaciente from '../components/TarjetaPaciente';
import './dashboard.css';


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