import { Link, useParams } from "react-router-dom";
import { usePacienteDetalle } from "../hooks/usePacienteDetalle";
import { useLecturas } from "../hooks/useLecturas";
import "./paciente.css";
const FACTOR_GOTEO = {
  NORMAL_GOTEO: 20,
  MICRO_GOTEO: 60,
};

const ETIQUETAS_GOTEO = {
  NORMAL_GOTEO: "Macrogoteo",
  MICRO_GOTEO: "Microgoteo",
};

function mililitrosPorHora(gotasPorMin, modoGoteo) {
  const factor = FACTOR_GOTEO[modoGoteo];
  if (!factor || !Number.isFinite(gotasPorMin)) return null;
  return Math.round((gotasPorMin * 60) / factor);
}


function formatearDuracion(minutos) {
  if (!Number.isFinite(minutos)) return "—";
  const horas = Math.floor(minutos / 60);
  const resto = Math.round(minutos % 60);
  if (horas === 0) return `${resto}m`;
  return `${horas}h ${String(resto).padStart(2, "0")}m`;
}


function horaDeFin(minutosRestantes) {
  if (!Number.isFinite(minutosRestantes)) return "—";
  const fin = new Date(Date.now() + minutosRestantes * 60000);
  return fin.toLocaleTimeString("es-MX", {
    hour: "2-digit",
    minute: "2-digit",
    hour12: false,
  });
}

function formatearMarca(iso) {
  if (!iso) return "—";
  const fecha = new Date(iso);
  if (Number.isNaN(fecha.getTime())) return "—";
  return fecha.toLocaleTimeString("es-MX", {
    hour: "2-digit",
    minute: "2-digit",
    second: "2-digit",
    hour12: false,
  });
}


function etiquetaCama(cama) {
  if (!cama) return "Sin cama";
  return cama.charAt(0).toUpperCase() + cama.slice(1);
}

function numero(valor, decimales = 1) {
  if (!Number.isFinite(valor)) return "—";
  return Number(valor.toFixed(decimales)).toLocaleString("es-MX");
}


function HistorialLecturas({ lecturas, modoGoteo, desactualizado }) {
  if (lecturas.length === 0) {
    return (
      <p className="estado-vacio-historial">
        Todavía no hay lecturas registradas para esta sesión.
      </p>
    );
  }

  
  const masRecientesPrimero = [...lecturas].reverse();

  return (
    <div className="seccion-historial">
      <div className="cabecera-historial">
        <span className="label-metrica">HISTORIAL DE LECTURAS</span>
        {desactualizado ? (
          <span className="chip-desactualizado" role="alert">
            Sin conexión · datos de la última lectura recibida
          </span>
        ) : (
          <span className="chip-actualizacion">
            {lecturas.length} {lecturas.length === 1 ? "registro" : "registros"}
          </span>
        )}
      </div>

      <div className="envoltura-tabla-lecturas">
        <table className="tabla-lecturas">
          <thead>
            <tr>
              <th scope="col">Hora</th>
              <th scope="col">Goteo</th>
              <th scope="col">Flujo</th>
              <th scope="col">Restante</th>
              <th scope="col">Tiempo</th>
            </tr>
          </thead>
          <tbody>
            {masRecientesPrimero.map((lectura, indice) => {
              const flujo = mililitrosPorHora(lectura.gotasPorMin, modoGoteo);
              return (
                <tr key={`${lectura.marcaTiempo}-${indice}`}>
                  <td>{formatearMarca(lectura.marcaTiempo)}</td>
                  <td>{numero(lectura.gotasPorMin)} gtt/min</td>
                  <td>{flujo === null ? "—" : `${flujo} ml/h`}</td>
                  <td>{numero(lectura.volRestante, 2)} ml</td>
                  <td>{formatearDuracion(lectura.tiempoRestanteMin)}</td>
                </tr>
              );
            })}
          </tbody>
        </table>
      </div>
    </div>
  );
}

export default function Paciente() {
  const { id } = useParams();

  const { detalle, enfermera, cargando, error } = usePacienteDetalle(id);

  const { lecturas, error: errorLecturas } = useLecturas(
    detalle?.sesion?.id ?? null,
    detalle?.lecturas ?? []
  );



  if (cargando) {
    return (
      <div className="contenedor-paciente-vista">
        <p className="estado-carga-vista">Cargando datos del paciente…</p>
      </div>
    );
  }

  if (error) {
    const esNoEncontrado = error.estado === 404;

    return (
      <div className="contenedor-paciente-vista">
        <div className="estado-error-vista" role="alert">
          <h2>
            {esNoEncontrado
              ? "Este paciente no está disponible"
              : "No se pudo cargar el paciente"}
          </h2>
          <p>
            {esNoEncontrado
              ? "El paciente no existe o no tiene una venoclisis activa en este momento."
              : "No fue posible contactar al servidor. Revisa la conexión e inténtalo de nuevo."}
          </p>
          <Link to="/pacientes" className="boton-volver">
            Volver al listado de pacientes
          </Link>
        </div>
      </div>
    );
  }

  const sesion = detalle?.sesion;

  if (!sesion) {
    return (
      <div className="contenedor-paciente-vista">
        <div className="estado-error-vista" role="alert">
          <h2>{detalle?.nombre ?? "Paciente"} no tiene venoclisis activa</h2>
          <p>No hay sesión ni lecturas que mostrar.</p>
          <Link to="/pacientes" className="boton-volver">
            Volver al listado de pacientes
          </Link>
        </div>
      </div>
    );
  }

  

  const ultima = lecturas.length > 0 ? lecturas[lecturas.length - 1] : null;

  const volRestante = ultima?.volRestante ?? sesion.volTotal;
  const volInfundido = Math.max(0, sesion.volTotal - volRestante);

  const porcentajeRestante =
    sesion.volTotal > 0 ? (volRestante / sesion.volTotal) * 100 : 0;
  const porcentajeInfundido = 100 - porcentajeRestante;

  const flujoActual = ultima
    ? mililitrosPorHora(ultima.gotasPorMin, sesion.modoGoteo)
    : null;

  const etiquetaGoteo = ETIQUETAS_GOTEO[sesion.modoGoteo] ?? "Sin modo";
  const factor = FACTOR_GOTEO[sesion.modoGoteo];
  const estaActiva = sesion.estado === "ACTIVA";


  return (
    <div className="contenedor-paciente-vista">
      {}
      <div className="tarjeta-info-superior">
        <div className="detalles-principales-paciente">
          <div className="fila-titulo-paciente">
            <h2>{detalle.nombre}</h2>
            <span className="insignia-permeable">
              ● Venoclisis {estaActiva ? "Activa" : sesion.estado}
            </span>
          </div>

          <div className="subdetalles-paciente">
            <span>{etiquetaCama(detalle.cama)}</span>
            <span className="separador-punto">•</span>
            <span>{detalle.sala}</span>
            <span className="separador-punto">•</span>
            <span>
              Enfermera a cargo:{" "}
              {enfermera ? enfermera.nombre : "No disponible"}
            </span>
            <span className="separador-punto">•</span>
            <span>Inicio de sesión: {formatearMarca(sesion.inicio)} hrs</span>
          </div>
        </div>
      </div>

      <div className="tarjeta-infusion-principal">
        {}
        <div className="cabecera-canal">
          <div className="info-canal-izq">
            <span className="texto-canal-pequeno">
              SESIÓN {sesion.id} • {detalle.sala.toUpperCase()}
            </span>
            <h3 className="titulo-solucion">
              {sesion.solucion} ({numero(sesion.volTotal, 0)} ml)
            </h3>
          </div>
          <div className="badges-canal-der">
            <span className="insignia-cateter">
              {etiquetaGoteo}
              {factor ? ` · ${factor} gtt/ml` : ""}
            </span>
          </div>
        </div>

        <div className="estado-curso-barra">
          {estaActiva
            ? "Venoclisis en curso"
            : `Venoclisis ${sesion.estado.toLowerCase()}`}
        </div>

        <div className="grid-monitoreo">
          {}
          <div className="columna-bolsa-visual">
            <div className="caja-visual-bolsa">
              <span className="capacidad-total">
                {numero(sesion.volTotal, 0)} ml
              </span>
              <div className="liquido-nivel">
                <span className="porcentaje-texto">
                  {Math.floor(porcentajeRestante)}%
                </span>
                <span className="ml-restantes">
                  {numero(volRestante, 2)} ML RESTANTES
                </span>
              </div>
              <div className="etiqueta-solucion-footer">{sesion.solucion}</div>
            </div>
          </div>

          {}
          <div className="columna-metricas-controles">
            <div className="fila-metricas-doble">
              {}
              <div className="card-metrica-item">
                <span className="label-metrica">VELOCIDAD DE FLUJO</span>
                <div className="valor-metrica-num">
                  {flujoActual === null ? "—" : flujoActual} <span>ml / h</span>
                </div>
                <span className="sub-estado-metrica">
                  Calculado con factor {factor ?? "—"} gtt/ml
                </span>
              </div>

              {}
              <div className="card-metrica-item">
                <span className="label-metrica">RITMO DE GOTEO (GTT)</span>
                <div className="valor-metrica-num">
                  {ultima ? numero(ultima.gotasPorMin) : "—"}{" "}
                  <span>gotas / min</span>
                </div>
                <span className="sub-estado-metrica verde">
                  {ultima
                    ? `Última lectura: ${formatearMarca(ultima.marcaTiempo)}`
                    : "Sin lecturas recibidas"}
                </span>
              </div>
            </div>

            <div className="fila-metricas-doble">
              {}
              <div className="card-metrica-item">
                <span className="label-metrica">VOLUMEN INFUNDIDO / VTBI</span>
                <div className="valor-metrica-num">
                  {numero(volInfundido, 2)} ml{" "}
                  <span>/ {numero(sesion.volTotal, 0)} ml</span>
                </div>
                <div
                  className="barra-progreso-vtbi"
                  role="progressbar"
                  aria-valuenow={Math.round(porcentajeInfundido)}
                  aria-valuemin={0}
                  aria-valuemax={100}
                  aria-label="Volumen infundido"
                >
                  <div
                    className="progreso-relleno"
                    style={{ width: `${porcentajeInfundido}%` }}
                  />
                </div>
                <span className="sub-estado-metrica">
                  Restante en bolsa:{" "}
                  <strong>{numero(volRestante, 2)} ml</strong>
                </span>
              </div>

              {}
              <div className="card-metrica-item">
                <span className="label-metrica">
                  TIEMPO RESTANTE DE INFUSIÓN
                </span>
                <div className="valor-metrica-num">
                  {ultima ? formatearDuracion(ultima.tiempoRestanteMin) : "—"}{" "}
                  <span>aprox.</span>
                </div>
                <span className="sub-estado-metrica">
                  Fin previsto:{" "}
                  <strong>
                    {ultima ? `${horaDeFin(ultima.tiempoRestanteMin)} hrs` : "—"}
                  </strong>
                </span>
              </div>
            </div>

            {}
            <div className="panel-inferior-controles">
              <div className="caja-estado-actual">
                <span className="label-metrica">ESTADO:</span>
                <div className="badge-activo-curso">
                  {estaActiva ? "Activo" : sesion.estado}
                </div>
              </div>
            </div>
          </div>
        </div>

        <HistorialLecturas
          lecturas={lecturas}
          modoGoteo={sesion.modoGoteo}
          desactualizado={Boolean(errorLecturas)}
        />
      </div>
    </div>
  );
}