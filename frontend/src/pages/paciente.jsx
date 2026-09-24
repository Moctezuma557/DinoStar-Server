import React from 'react';
import './paciente.css';

export default function Paciente() {
  return (
    <div className="contenedor-paciente-vista">
      
      {/* 1. Tarjeta Superior: Datos Generales del Paciente */}
      <div className="tarjeta-info-superior">
       
        <div className="detalles-principales-paciente">
          <div className="fila-titulo-paciente">
            <h2>Elena Rodríguez</h2>
            <span className="insignia-permeable">● Venoclisis Activa y Permeable</span>
          </div>
          <div className="subdetalles-paciente">
            <span>Cama-02</span>
            <span className="separador-punto">•</span>
            <span>64 Años (Femenino)</span>
            <span className="separador-punto">•</span>
            <span> Enfermera a cargo: Lic. Sarah Jiménez</span>
            <span className="separador-punto">•</span>
            <span> Ingreso: 12 Mayo 2024 (08:30 hrs)</span>
          </div>
        </div>
      </div>

      <div className="tarjeta-infusion-principal">
        {/* Cabecera del canal de infusión */}
        <div className="cabecera-canal">
          <div className="info-canal-izq">
            <span className="texto-canal-pequeno">CANAL 01 • VÍA PERIFÉRICA DERECHA</span>
            <h3 className="titulo-solucion">Solución Salina Fisiológica 0.9% (1000 ml)</h3>
          </div>
          <div className="badges-canal-der">
            <span className="insignia-cateter">Catéter 20G</span>
          </div>
        </div>
        
        <div className="estado-curso-barra">
           Venoclisis en Curso (Flujo Continuo)
        </div>

        <div className="grid-monitoreo">
          
          {/* Columna Izquierda: Gráfico de bolsa / Nivel */}
          <div className="columna-bolsa-visual">
            <div className="caja-visual-bolsa">
              <span className="capacidad-total">1000 ml</span>
              <div className="liquido-nivel">
                <span className="porcentaje-texto">82%</span>
                <span className="ml-restantes">820 ML RESTANTES</span>
              </div>
              <div className="etiqueta-solucion-footer">Solución 0.9%</div>
            </div>
            
          </div>

          {/* Columna Derecha: Métricas y Controles */}
          <div className="columna-metricas-controles">
            
            <div className="fila-metricas-doble">
              {/* Flujo */}
              <div className="card-metrica-item">
                <span className="label-metrica">VELOCIDAD DE FLUJO</span>
                <div className="valor-metrica-num">120 <span>ml / h</span></div>
                <span className="sub-estado-metrica verde">Tasa Programada Estable</span>
              </div>

              {/* Goteo */}
              <div className="card-metrica-item">
                <span className="label-metrica">RITMO DE GOTEO (GTT)</span>
                <div className="valor-metrica-num">40 <span>gotas / min</span></div>
                <span className="sub-estado-metrica verde"> Flujo Continuo sin pausas</span>
              </div>
            </div>

            <div className="fila-metricas-doble">
              {/* Volumen infundido */}
              <div className="card-metrica-item">
                <span className="label-metrica">VOLUMEN INFUNDIDO / VTBI</span>
                <div className="valor-metrica-num">180 ml <span>/ 1000 ml</span></div>
                <div className="barra-progreso-vtbi"><div className="progreso-relleno" style={{width: '18%'}}></div></div>
                <span className="sub-estado-metrica">Restante en bolsa: <strong>820 ml</strong></span>
              </div>

              {/* Tiempo restante */}
              <div className="card-metrica-item">
                <span className="label-metrica">TIEMPO RESTANTE DE INFUSIÓN</span>
                <div className="valor-metrica-num">5h 20m <span>aprox.</span></div>
                <span className="sub-estado-metrica">Fin previsto: <strong>19:40 hrs</strong></span>
              </div>
            </div>

            {/* Estado inferior */}
            <div className="panel-inferior-controles">
              <div className="caja-estado-actual">
                <span className="label-metrica">ESTADO:</span>
                <div className="badge-activo-curso">
                  Activo
                </div>
              </div>
              
            </div>

          </div>

        </div>

      </div>

    </div>
  );
}