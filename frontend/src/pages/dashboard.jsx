import React, { useState } from 'react';
import './dashboard.css';

export default function Dashboard() {
  // Simulación: Puedes cambiar este array a [] para probar el mensaje de "Sin pacientes asignados"
  const [pacientes] = useState([
     
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

      {/* Sección principal de contenido */}
      <div className="seccion-contenido-dashboard">
        {pacientes.length === 0 ? (
          <div className="estado-vacio-dashboard">
            <h3>Sin pacientes asignados</h3>
          </div>
        ) : (
          <div className="grid-tarjetas-pacientes">
            {pacientes.map((paciente) => (
              <div key={paciente.id} className="tarjeta-paciente-resumen">
                <div className="card-header-paciente">
                  <span className="badge-estado-estable">● {paciente.estado}</span>
                  <span className="badge-cama">{paciente.cama}</span>
                </div>
                
                <div className="card-body-paciente">
                  <h3>{paciente.nombre}</h3>
                  <p>{paciente.edad}</p>
                  
                  <div className="info-infusion-resumen">
                    <div className="info-row">
                      <span>Solución:</span>
                      <strong>{paciente.solucion}</strong>
                    </div>
                    <div className="info-row">
                      <span>Nivel:</span>
                      <strong>{paciente.porcentaje}</strong>
                    </div>
                    <div className="info-row">
                      <span>Goteo:</span>
                      <strong>{paciente.goteo}</strong>
                    </div>
                  </div>
                </div>

                <div className="card-footer-paciente">
                  <button className="btn-ver-detalle">Ver Detalle</button>
                </div>
              </div>
            ))}
          </div>
        )}
      </div>

    </div>
  );
}