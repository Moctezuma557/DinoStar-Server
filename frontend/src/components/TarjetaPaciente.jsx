import React from 'react';
import './tarjetapaciente.css';

export default function TarjetaPaciente({ 
  nombre, 
  cama, 
  gotasPorMin, 
  volRestante, 
  tiempoRestante, 
  estado 
}) {
  return (
    <div className={`tarjeta-paciente-componente estado-${estado}`}>
      <div className="tarjeta-paciente-header">
        <span className="badge-cama">{cama}</span>
        <div className={`indicador-estado estado-${estado}`}>
          ● {estado.charAt(0).toUpperCase() + estado.slice(1)}
        </div>
      </div>

      <div className="tarjeta-paciente-body">
        <h3>{nombre}</h3>
        
        <div className="info-infusion-resumen">
          <div className="info-row">
            <span>Goteo:</span>
            <strong>{gotasPorMin} gtt/min</strong>
          </div>
          <div className="info-row">
            <span>Volumen Restante:</span>
            <strong>{volRestante} mL</strong>
          </div>
          <div className="info-row">
            <span>Tiempo Restante:</span>
            <strong>{tiempoRestante} min</strong>
          </div>
        </div>
      </div>

      <div className="card-footer-paciente">
        <button className="btn-ver-detalle">Ver Detalle</button>
      </div>
    </div>
  );
}