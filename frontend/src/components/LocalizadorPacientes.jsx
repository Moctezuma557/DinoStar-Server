import { useEffect, useMemo, useState } from "react";
import Icono from "./Icono";
import { useDebounce } from "../hooks/useDebounce";
import { useEnfermeras } from "../hooks/useEnfermeras";
import { usePacientes } from "../hooks/usePacientes";
import logoDinoStar from "../assets/Logo.png";
import estilos from "./LocalizadorPacientes.module.css";

function normalizar(texto) {
  return (texto ?? "")
    .normalize("NFD")
    .replace(/[̀-ͯ]/g, "")
    .toLowerCase()
    .trim();
}

const ETIQUETAS_GOTEO = {
  NORMAL_GOTEO: "Macrogoteo",
  MICRO_GOTEO: "Microgoteo",
};

function etiquetaGoteo(modo) {
  return ETIQUETAS_GOTEO[modo] ?? "Sin modo";
}


function etiquetaCama(cama) {
  if (!cama) return "Sin cama";
  return cama.charAt(0).toUpperCase() + cama.slice(1);
}

function TarjetaCama({ paciente, onSeleccionar }) {
  return (
    <article className={estilos.cama}>
      <header className={estilos.camaEncabezado}>
        <span className={estilos.chipCama}>{etiquetaCama(paciente.cama)}</span>

        <span className={estilos.chipGoteo}>
          <Icono nombre="gota" tamano={14} aria-hidden="true" />
          {etiquetaGoteo(paciente.modoGoteo)}
        </span>

        <button
          type="button"
          className={estilos.botonSeleccionar}
          onClick={() => onSeleccionar(paciente)}
        >
          Seleccionar
        </button>
      </header>

      <h4 className={estilos.camaNombre}>{paciente.nombre}</h4>

      <p className={estilos.camaDetalle}>
        {paciente.solucion} · {paciente.volTotal} ml
      </p>

      <p className={estilos.camaSala}>
        <Icono nombre="ubicacion" tamano={14} aria-hidden="true" />
        {paciente.sala}
      </p>
    </article>
  );
}

function ListaDeCamas({ pacientes, cargando, error, mensajeVacio, onSeleccionar }) {
  if (cargando) {
    return (
      <p className={estilos.estado}>
        <Icono nombre="sincronizar" tamano={16} aria-hidden="true" />
        Consultando pacientes…
      </p>
    );
  }

  if (error) {
    return (
      <p className={estilos.estadoError} role="alert">
        <Icono nombre="advertencia" tamano={16} aria-hidden="true" />
        No se pudo contactar al servidor. Revisa la conexión e inténtalo de nuevo.
      </p>
    );
  }

  if (pacientes.length === 0) {
    return <p className={estilos.estado}>{mensajeVacio}</p>;
  }

  return (
    <div className={estilos.lista}>
      {pacientes.map((paciente) => (
        <TarjetaCama
          key={paciente.id}
          paciente={paciente}
          onSeleccionar={onSeleccionar}
        />
      ))}
    </div>
  );
}

function PacientesDeEnfermera({ enfermera, onSeleccionar }) {
  const { pacientes, cargando, error } = usePacientes({
    enfermeraId: enfermera.id,
  });

  return (
    <>
      <div className={estilos.subtitulo}>
        <Icono nombre="verificado" tamano={16} aria-hidden="true" />
        <span className={estilos.subtituloTexto}>
          Pacientes asignados a {enfermera.nombre}
          {!cargando && !error && ` (${pacientes.length})`}
        </span>
        <span className={estilos.chipGuardia}>Guardia activa</span>
      </div>

      <ListaDeCamas
        pacientes={pacientes}
        cargando={cargando}
        error={error}
        mensajeVacio="Esta enfermera no tiene pacientes con venoclisis activa."
        onSeleccionar={onSeleccionar}
      />
    </>
  );
}

export default function LocalizadorPacientes({ onCerrar, onConfirmar }) {
  const [enfermeraActiva, setEnfermeraActiva] = useState(null);
  const [filtroEnfermera, setFiltroEnfermera] = useState("");
  const [filtroPaciente, setFiltroPaciente] = useState("");

  const busquedaPaciente = useDebounce(filtroPaciente);

  const {
    enfermeras,
    cargando: cargandoEnfermeras,
    error: errorEnfermeras,
  } = useEnfermeras();

  const {
    pacientes: resultadosBusqueda,
    cargando: cargandoBusqueda,
    error: errorBusqueda,
  } = usePacientes({ nombre: busquedaPaciente });

  const enfermerasFiltradas = useMemo(() => {
    const aguja = normalizar(filtroEnfermera);
    if (!aguja) return enfermeras;
    return enfermeras.filter((enfermera) =>
      normalizar(enfermera.nombre).includes(aguja)
    );
  }, [enfermeras, filtroEnfermera]);
  useEffect(() => {
    function alPresionarTecla(evento) {
      if (evento.key === "Escape") onCerrar();
    }
    document.addEventListener("keydown", alPresionarTecla);
    return () => document.removeEventListener("keydown", alPresionarTecla);
  }, [onCerrar]);

  return (
    <section
      className={estilos.dialogo}
      role="dialog"
      aria-modal="true"
      aria-labelledby="titulo-localizador"
    >
      <header className={estilos.encabezado}>
        <img src={logoDinoStar} alt="DinoStar" className={estilos.marca} />

        <div className={estilos.encabezadoTextos}>
          <h2 id="titulo-localizador" className={estilos.titulo}>
            Localizador de pacientes
          </h2>
          <p className={estilos.subtituloDialogo}>
            Selecciona el paciente por personal a cargo o localización directa
            en cama para monitorear la venoclisis en tiempo real.
          </p>
        </div>

        <span className={estilos.puntoEstado} aria-hidden="true" />

        <button
          type="button"
          className={estilos.botonCerrar}
          onClick={onCerrar}
          aria-label="Cerrar localizador"
        >
          <Icono nombre="cerrar" tamano={18} aria-hidden="true" />
        </button>
      </header>

      <div className={estilos.cuerpo}>
        {}
        <div className={estilos.columna}>
          <div className={estilos.columnaEncabezado}>
            <span className={estilos.cajaIcono}>
              <Icono nombre="maletin" tamano={18} aria-hidden="true" />
            </span>
            <div className={estilos.columnaTextos}>
              <h3 className={estilos.columnaTitulo}>Enfermera a cargo</h3>
              <p className={estilos.columnaDescripcion}>
                Carga y pacientes bajo guardia activa
              </p>
            </div>
            {enfermeraActiva && (
              <span className={estilos.chipTurno}>
                Turno {enfermeraActiva.turno}
              </span>
            )}
          </div>

          <p className={estilos.etiquetaSeccion}>Seleccionar personal en turno</p>

          <label className={estilos.campo}>
            <Icono nombre="buscar" tamano={16} aria-hidden="true" />
            <input
              type="search"
              className={estilos.entrada}
              placeholder="Buscar enfermera por nombre…"
              value={filtroEnfermera}
              onChange={(evento) => setFiltroEnfermera(evento.target.value)}
              aria-label="Buscar enfermera por nombre"
            />
          </label>

          {cargandoEnfermeras && (
            <p className={estilos.estado}>
              <Icono nombre="sincronizar" tamano={16} aria-hidden="true" />
              Consultando personal en turno…
            </p>
          )}

          {errorEnfermeras && (
            <p className={estilos.estadoError} role="alert">
              <Icono nombre="advertencia" tamano={16} aria-hidden="true" />
              No se pudo cargar el personal en turno.
            </p>
          )}

          {!cargandoEnfermeras &&
            !errorEnfermeras &&
            enfermerasFiltradas.length === 0 && (
              <p className={estilos.estado}>
                Ninguna enfermera coincide con la búsqueda.
              </p>
            )}

          {!cargandoEnfermeras && !errorEnfermeras && enfermerasFiltradas.length > 0 && (
            <div className={estilos.rejillaEnfermeras}>
              {enfermerasFiltradas.map((enfermera) => {
                const activa = enfermeraActiva?.id === enfermera.id;
                return (
                  <button
                    key={enfermera.id}
                    type="button"
                    className={`${estilos.enfermera} ${activa ? estilos.enfermeraActiva : ""}`}
                    onClick={() => setEnfermeraActiva(enfermera)}
                    aria-pressed={activa}
                  >
                    <span className={estilos.avatar} aria-hidden="true">
                      {enfermera.iniciales}
                    </span>
                    <span className={estilos.enfermeraTextos}>
                      <span className={estilos.enfermeraNombre}>
                        {enfermera.nombre}
                      </span>
                      <span className={estilos.enfermeraTurno}>
                        Turno {enfermera.turno}
                      </span>
                    </span>
                  </button>
                );
              })}
            </div>
          )}

          {enfermeraActiva ? (
            <PacientesDeEnfermera
              key={enfermeraActiva.id}
              enfermera={enfermeraActiva}
              onSeleccionar={onConfirmar}
            />
          ) : (
            <p className={estilos.estadoVacio}>
              <Icono nombre="usuarios" tamano={20} aria-hidden="true" />
              Selecciona personal en turno para ver sus pacientes asignados.
            </p>
          )}
        </div>

        {/* ---------------- Columna derecha ---------------- */}
        <div className={estilos.columna}>
          <div className={estilos.columnaEncabezado}>
            <span className={estilos.cajaIcono}>
              <Icono nombre="buscar" tamano={18} aria-hidden="true" />
            </span>
            <div className={estilos.columnaTextos}>
              <h3 className={estilos.columnaTitulo}>Búsqueda directa</h3>
              <p className={estilos.columnaDescripcion}>
                Por nombre de paciente o número de cama
              </p>
            </div>
          </div>

          <label className={estilos.campo}>
            <Icono nombre="buscar" tamano={16} aria-hidden="true" />
            <input
              type="search"
              className={estilos.entrada}
              placeholder="Buscar por paciente…"
              value={filtroPaciente}
              onChange={(evento) => setFiltroPaciente(evento.target.value)}
              aria-label="Buscar paciente por nombre"
            />
          </label>

          {!cargandoBusqueda && !errorBusqueda && (
            <p className={estilos.contador}>
              {resultadosBusqueda.length}{" "}
              {resultadosBusqueda.length === 1 ? "cama activa" : "camas activas"}
            </p>
          )}

          <ListaDeCamas
            pacientes={resultadosBusqueda}
            cargando={cargandoBusqueda}
            error={errorBusqueda}
            mensajeVacio="Ningún paciente coincide con la búsqueda."
            onSeleccionar={onConfirmar}
          />
        </div>
      </div>
    </section>
  );
}