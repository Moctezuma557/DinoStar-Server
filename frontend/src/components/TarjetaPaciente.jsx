import { analizarVolumen } from '../utils/volumen';
import './tarjetapaciente.css';

export default function TarjetaPaciente({
  nombre,
  edad,
  idPaciente,
  gotasPorMin,
  estadoRitmo,
  flujoActual,
  metaFlujo,
  volRestante,
  volTotal,
  tiempoRestante,
  estado,
  textoEstado,
  nombreSolucion,
  tipoBajante
}) {
  const estadoSeguro = estado ?? "normal";
  const textoEstadoSeguro = textoEstado || "GOTEO REGULAR";
  const volumen = analizarVolumen(volRestante, volTotal);

  return (
    <div className={`tarjeta-paciente-componente estado-${estadoSeguro}`}>
      <div className={`tarjeta-paciente-header estado-${estadoSeguro}`}>
        <div className="header-estado-info">
          <span className="texto-estado-header">{textoEstadoSeguro}</span>
        </div>
        <span className="badge-estable">{estadoSeguro.toUpperCase() === 'NORMAL' ? 'ESTABLE' : estadoSeguro.toUpperCase()}</span>
      </div>

      <div className="tarjeta-paciente-body">
        {}
        <div className="paciente-info-principal">
          <div className="paciente-nombre-seccion">
            <h3>{nombre}</h3>
            {edad != null && <span className="badge-edad">{edad} Años</span>}
          </div>
          <span className="paciente-detalles-secundarios">{idPaciente}</span>
        </div>

        <div className="solucion-infusion-card">
          <div className="solucion-info-izquierda">
            <span className="nombre-solucion">{nombreSolucion}</span>
          </div>
        </div>

        {}
        <div className="grid-metricas-infusion">
          <div className="metrica-card">
            <div className="metrica-header">
              <span className="metrica-titulo">RITMO DE GOTEO</span>
            </div>
            <div className="metrica-valor-grupo">
              {}
              <span className="valor-principal">{gotasPorMin ?? "—"}</span>
              <span className="unidad-medida">gtt/min</span>
            </div>
            <span className="metrica-subtext texto-verde">{estadoRitmo}</span>
          </div>

          <div className="metrica-card">
            <div className="metrica-header">
              <span className="metrica-titulo">FLUJO ACTUAL</span>
            </div>
            <div className="metrica-valor-grupo">
              <span className="valor-principal">{flujoActual ?? "—"}</span>
              <span className="unidad-medida">ml/h</span>
            </div>
            <span className="badge-bajante">{tipoBajante}</span>
            {metaFlujo != null && (
              <span className="metrica-subtext">Meta: {metaFlujo} ml/h</span>
            )}
          </div>
        </div>

        {/* Volumen Restante y Tiempo */}
        <div className="info-volumen-seccion">
          <div className="volumen-header-row">
            <span className="volumen-titulo-label">Volumen Restante</span>
            <span
              className={
                volumen.hayDato
                  ? "volumen-porcentaje-valor"
                  : "volumen-porcentaje-valor volumen-sin-lectura"
              }
            >
              {volumen.texto}
            </span>
          </div>

          {volumen.hayDato ? (
            <div
              className="barra-progreso-container"
              role="progressbar"
              aria-valuenow={volumen.porcentaje}
              aria-valuemin={0}
              aria-valuemax={100}
              aria-label="Volumen restante en la bolsa"
            >
              <div
                className={
                  volumen.incoherente
                    ? "barra-progreso-fill barra-incoherente"
                    : "barra-progreso-fill"
                }
                style={{ width: `${volumen.porcentaje}%` }}
              ></div>
            </div>
          ) : (
            <div
              className="barra-progreso-container barra-sin-dato"
              aria-hidden="true"
            ></div>
          )}

          {volumen.incoherente && (
            <p className="aviso-volumen-incoherente" role="alert">
              La lectura reporta {volumen.restante} ml en una bolsa de{" "}
              {volumen.total} ml. El dato recibido es incoherente y el
              porcentaje se muestra acotado.
            </p>
          )}

          <div className="tiempo-restante-row">
            <span className="tiempo-label">Tiempo Restante</span>
            <span className="tiempo-valor">{tiempoRestante ?? "Sin lectura"}</span>
          </div>
        </div>
      </div>
    </div>
  );
}