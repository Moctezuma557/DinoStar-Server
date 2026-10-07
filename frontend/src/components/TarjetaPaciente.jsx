import React from 'react';
import './tarjetapaciente.css';

export default function TarjetaPaciente({ 
  nombre, 
  edad, 
  idPaciente,  
  gotasPorMin, 
  estadoRitmo, 
  flujoActual, 
  metaFlujo, 
  porcentajeVolumen, 
  volRestante, 
  volTotal, 
  tiempoRestante, 
  estado,
  textoEstado,
  nombreSolucion,
  tipoBajante
}) {
  return (
    <div className={`tarjeta-paciente-componente estado-${estado}`}>
      <div className={`tarjeta-paciente-header estado-${estado}`}>
        <div className="header-estado-info">
          <span className="icono-estado">
            {estado === 'normal' }
            {estado === 'atencion' }
            {estado === 'alerta'}
          </span>
          <span className="texto-estado-header">{textoEstado || "GOTEO REGULAR"}</span>
        </div>
        <span className="badge-estable">{estado.toUpperCase() === 'NORMAL' ? 'ESTABLE' : estado.toUpperCase()}</span>
      </div>

      <div className="tarjeta-paciente-body">
        {/* Información del Paciente */}
        <div className="paciente-info-principal">
          <div className="paciente-nombre-seccion">
            <h3>{nombre}</h3>
            <span className="badge-edad">{edad} Años</span>
          </div>
          <span className="paciente-detalles-secundarios">{idPaciente}</span>
        </div>

        <div className="solucion-infusion-card">
          <div className="solucion-info-izquierda">
            <span className="nombre-solucion">{nombreSolucion}</span>
          </div>
          <span className="badge-bajante">{tipoBajante}</span>
        </div>

        {/* Ritmo de Goteo y Flujo Actual */}
        <div className="grid-metricas-infusion">
          <div className="metrica-card">
            <div className="metrica-header">
              <span className="metrica-titulo">RITMO DE GOTEO</span>
            </div>
            <div className="metrica-valor-grupo">
              <span className="valor-principal">{gotasPorMin}</span>
              <span className="unidad-medida">gtt/min</span>
            </div>
            <span className="metrica-subtext texto-verde">{estadoRitmo}</span>
          </div>

          <div className="metrica-card">
            <div className="metrica-header">
              <span className="metrica-titulo">FLUJO ACTUAL</span>
            </div>
            <div className="metrica-valor-grupo">
              <span className="valor-principal">{flujoActual}</span>
              <span className="unidad-medida">ml/h</span>
            </div>
            <span className="metrica-subtext">Meta: {metaFlujo} ml/h</span>
          </div>
        </div>


        

        {/* Volumen Restante y Tiempo */}
        <div className="info-volumen-seccion">
          <div className="volumen-header-row">
            <span className="volumen-titulo-label">Volumen Restante</span>
            <span className="volumen-porcentaje-valor">{porcentajeVolumen}% • {volRestante} / {volTotal} ml</span>
          </div>
          
          <div className="barra-progreso-container">
            <div 
              className="barra-progreso-fill" 
              style={{ width: `${porcentajeVolumen}%` }}
            ></div>
          </div>

          <div className="tiempo-restante-row">
            <span className="tiempo-label">Tiempo Restante</span>
            <span className="tiempo-valor">{tiempoRestante}</span>
          </div>
        </div>
      </div>
    </div>
  );
}