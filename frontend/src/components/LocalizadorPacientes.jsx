import { useEffect, useMemo, useState } from "react";
import Icono from "./Icono";
import styles from "./LocalizadorPacientes.module.css";
import logoDinoStar from "../assets/Logo.png";

const ENFERMERAS = [
  { id: "sarah", nombre: "Lic. Sarah J.", iniciales: "SJ" },
  { id: "carlos", nombre: "Lic. Carlos M.", iniciales: "CM" },
  { id: "elena", nombre: "Enf. Elena R.", iniciales: "ER" },
  { id: "roberto", nombre: "Lic. Roberto P.", iniciales: "RP" },
  { id: "maria", nombre: "Enf. María S.", iniciales: "MS" },
  { id: "andrea", nombre: "Lic. Andrea G.", iniciales: "AG" },
];

const PACIENTES = [
  {
    id: 1,
    nombre: "Mateo Silva",
    cama: "Cama-04",
    enfermeraId: "sarah",
    solucion: "Solución Fisiológica 0.9%",
    envase: "Frasco 500 ml",
    tasaMlH: 65,
    tasaGtt: 22,
    porcentaje: 64,
    volumenRestante: 320,
    estado: "normal",
  },
  {
    id: 2,
    nombre: "Sofía Méndez",
    cama: "Cama-03",
    enfermeraId: "sarah",
    solucion: "Ringer Lactato",
    envase: "Bolsa 1000 ml",
    tasaMlH: 80,
    tasaGtt: 27,
    porcentaje: 58,
    volumenRestante: 580,
    estado: "normal",
  },
  {
    id: 3,
    nombre: "Elena Rodríguez",
    cama: "Cama-02",
    enfermeraId: "sarah",
    solucion: "Solución Salina 0.9%",
    envase: "Frasco 500 ml",
    tasaMlH: 120,
    tasaGtt: 40,
    porcentaje: 74,
    volumenRestante: 370,
    estado: "normal",
  },

  {
    id: 4,
    nombre: "Mateo Morales",
    cama: "Cama-01",
    enfermeraId: "carlos",
    solucion: "Dextrosa 5%",
    envase: "Frasco 250 ml",
    tasaMlH: 50,
    tasaGtt: 17,
    porcentaje: 18,
    volumenRestante: 45,
    estado: "atencion",
    aviso: "Bolsa baja",
  },
  {
    id: 5,
    nombre: "Joaquín Rivas",
    cama: "Cama-05",
    enfermeraId: "carlos",
    solucion: "Ringer Lactato",
    envase: "Bolsa 1000 ml",
    tasaMlH: 90,
    tasaGtt: 30,
    porcentaje: 81,
    volumenRestante: 810,
    estado: "normal",
  },
  {
    id: 6,
    nombre: "Renata Ocampo",
    cama: "Cama-08",
    enfermeraId: "carlos",
    solucion: "Solución Salina 0.9%",
    envase: "Frasco 500 ml",
    tasaMlH: 75,
    tasaGtt: 25,
    porcentaje: 47,
    volumenRestante: 235,
    estado: "normal",
  },
  {
    id: 7,
    nombre: "Iván Delgado",
    cama: "Cama-12",
    enfermeraId: "carlos",
    solucion: "Glucosa al 5%",
    envase: "Frasco 250 ml",
    tasaMlH: 45,
    tasaGtt: 15,
    porcentaje: 62,
    volumenRestante: 155,
    estado: "normal",
  },

  {
    id: 8,
    nombre: "Lucía Ferrer",
    cama: "Cama-06",
    enfermeraId: "elena",
    solucion: "Solución Salina 0.9%",
    envase: "Frasco 500 ml",
    tasaMlH: 40,
    tasaGtt: 13,
    porcentaje: 9,
    volumenRestante: 45,
    estado: "alerta",
    aviso: "Sin flujo",
  },
  {
    id: 9,
    nombre: "Alonso Barrera",
    cama: "Cama-09",
    enfermeraId: "elena",
    solucion: "Ringer Lactato",
    envase: "Bolsa 1000 ml",
    tasaMlH: 100,
    tasaGtt: 33,
    porcentaje: 69,
    volumenRestante: 690,
    estado: "normal",
  },

  
  {
    id: 10,
    nombre: "Tomás Aguirre",
    cama: "Cama-07",
    enfermeraId: "roberto",
    solucion: "Glucosa al 5%",
    envase: "Frasco 250 ml",
    tasaMlH: 70,
    tasaGtt: 23,
    porcentaje: 52,
    volumenRestante: 130,
    estado: "normal",
  },
  {
    id: 11,
    nombre: "Pilar Navarro",
    cama: "Cama-14",
    enfermeraId: "roberto",
    solucion: "Solución Fisiológica 0.9%",
    envase: "Frasco 500 ml",
    tasaMlH: 60,
    tasaGtt: 20,
    porcentaje: 35,
    volumenRestante: 175,
    estado: "normal",
  },

  {
    id: 12,
    nombre: "Daniela Cortés",
    cama: "Cama-10",
    enfermeraId: "maria",
    solucion: "Ringer Lactato",
    envase: "Bolsa 1000 ml",
    tasaMlH: 85,
    tasaGtt: 28,
    porcentaje: 77,
    volumenRestante: 770,
    estado: "normal",
  },
  {
    id: 13,
    nombre: "Esteban Ruiz",
    cama: "Cama-11",
    enfermeraId: "maria",
    solucion: "Solución Salina 0.9%",
    envase: "Frasco 500 ml",
    tasaMlH: 55,
    tasaGtt: 18,
    porcentaje: 41,
    volumenRestante: 205,
    estado: "normal",
  },
  {
    id: 14,
    nombre: "Carmen Olvera",
    cama: "Cama-15",
    enfermeraId: "maria",
    solucion: "Dextrosa 5%",
    envase: "Frasco 250 ml",
    tasaMlH: 48,
    tasaGtt: 16,
    porcentaje: 88,
    volumenRestante: 220,
    estado: "normal",
  },
  {
    id: 15,
    nombre: "Héctor Palma",
    cama: "Cama-17",
    enfermeraId: "maria",
    solucion: "Solución Fisiológica 0.9%",
    envase: "Frasco 500 ml",
    tasaMlH: 95,
    tasaGtt: 32,
    porcentaje: 56,
    volumenRestante: 280,
    estado: "normal",
  },
  {
    id: 16,
    nombre: "Noemí Zúñiga",
    cama: "Cama-18",
    enfermeraId: "maria",
    solucion: "Ringer Lactato",
    envase: "Bolsa 1000 ml",
    tasaMlH: 35,
    tasaGtt: 12,
    porcentaje: 22,
    volumenRestante: 220,
    estado: "atencion",
    aviso: "Goteo irregular",
  },

  
  {
    id: 17,
    nombre: "Ignacio Lara",
    cama: "Cama-13",
    enfermeraId: "andrea",
    solucion: "Glucosa al 5%",
    envase: "Frasco 250 ml",
    tasaMlH: 52,
    tasaGtt: 17,
    porcentaje: 73,
    volumenRestante: 183,
    estado: "normal",
  },
  {
    id: 18,
    nombre: "Teresa Quiroz",
    cama: "Cama-16",
    enfermeraId: "andrea",
    solucion: "Solución Salina 0.9%",
    envase: "Frasco 500 ml",
    tasaMlH: 68,
    tasaGtt: 23,
    porcentaje: 44,
    volumenRestante: 220,
    estado: "normal",
  },
  {
    id: 19,
    nombre: "Bruno Salcedo",
    cama: "Cama-19",
    enfermeraId: "andrea",
    solucion: "Solución Fisiológica 0.9%",
    envase: "Frasco 500 ml",
    tasaMlH: 110,
    tasaGtt: 37,
    porcentaje: 31,
    volumenRestante: 155,
    estado: "normal",
  },
];

const ETIQUETA_ESTADO = {
  normal: "Normal",
  atencion: "Atención",
  alerta: "Alerta",
};

function normalizar(texto) {
  return texto
    .toLowerCase()
    .normalize("NFD")
    .replace(/[̀-ͯ]/g, "");
}


function TarjetaCama({ paciente, seleccionado, onSeleccionar, compacta }) {
  return (
    <article
      className={`${styles.cama} ${styles[`cama--${paciente.estado}`]} ${
        seleccionado ? styles.camaSeleccionada : ""
      }`}
    >
      <header className={styles.camaEncabezado}>
        <span className={styles.camaEtiqueta}>{paciente.cama}</span>

        <div className={styles.camaAcciones}>
          {paciente.aviso ? (
            <span className={styles.avisoEstado}>
              <Icono nombre="advertencia" tamano={13} />
              {paciente.aviso}
            </span>
          ) : (
            !compacta && (
              <span className={styles.badgeEstado}>
                <span className={styles.puntoEstado} aria-hidden="true" />
                {ETIQUETA_ESTADO[paciente.estado]}
              </span>
            )
          )}

          <button
            type="button"
            className={`${styles.botonSeleccionar} ${
              seleccionado ? styles.botonSeleccionado : ""
            }`}
            onClick={() => onSeleccionar(paciente.id)}
            aria-pressed={seleccionado}
          >
            {seleccionado && <Icono nombre="verificado" tamano={14} />}
            {seleccionado ? "Seleccionado" : "Seleccionar"}
          </button>
        </div>
      </header>

      <h4 className={styles.camaPaciente}>{paciente.nombre}</h4>

      <p className={styles.camaSolucion}>
        {paciente.solucion} · {paciente.envase}
      </p>

      <div className={styles.camaMetricas}>
        <span>
          Tasa:{" "}
          <strong>
            {paciente.tasaMlH} ml/h ({paciente.tasaGtt} gtt/min)
          </strong>
        </span>
        <span className={styles.camaRestante}>
          {paciente.porcentaje}% restante ({paciente.volumenRestante} ml)
        </span>
      </div>

      {}
      <div
        className={styles.barra}
        role="progressbar"
        aria-valuenow={paciente.porcentaje}
        aria-valuemin={0}
        aria-valuemax={100}
        aria-label={`Volumen restante en ${paciente.cama}`}
      >
        <span
          className={styles.barraRelleno}
          style={{ width: `${paciente.porcentaje}%` }}
        />
      </div>
    </article>
  );
}

export default function LocalizadorPacientes({ onCerrar, onConfirmar }) {
  const [busquedaEnfermera, setBusquedaEnfermera] = useState("");
  const [enfermeraId, setEnfermeraId] = useState(ENFERMERAS[0].id);
  const [busquedaPaciente, setBusquedaPaciente] = useState("");
  const [pacienteId, setPacienteId] = useState(null);

  useEffect(() => {
    if (!onCerrar) return undefined;

    const alPresionar = (evento) => {
      if (evento.key === "Escape") onCerrar();
    };

    document.addEventListener("keydown", alPresionar);
    return () => document.removeEventListener("keydown", alPresionar);
  }, [onCerrar]);

  const enfermerasVisibles = useMemo(() => {
    const termino = normalizar(busquedaEnfermera.trim());
    if (termino === "") return ENFERMERAS;
    return ENFERMERAS.filter((e) => normalizar(e.nombre).includes(termino));
  }, [busquedaEnfermera]);

  const enfermera =
    ENFERMERAS.find((e) => e.id === enfermeraId) ?? ENFERMERAS[0];

  const asignados = useMemo(
    () => PACIENTES.filter((p) => p.enfermeraId === enfermeraId),
    [enfermeraId]
  );

  const resultados = useMemo(() => {
    const termino = normalizar(busquedaPaciente.trim());
    if (termino === "") return PACIENTES;
    return PACIENTES.filter((p) =>
      normalizar(`${p.nombre} ${p.cama}`).includes(termino)
    );
  }, [busquedaPaciente]);

  const seleccionado = PACIENTES.find((p) => p.id === pacienteId) ?? null;

  return (
    <section
      className={styles.cuadro}
      role="dialog"
      aria-modal="false"
      aria-labelledby="titulo-localizador"
    >
      <header className={styles.encabezado}>
        <img src={logoDinoStar} alt="DinoStar" className={styles.marca} />

        <div className={styles.encabezadoTexto}>
          <h2 id="titulo-localizador" className={styles.titulo}>
            Localizador de pacientes
          </h2>
          <p className={styles.subtitulo}>
            Selecciona el paciente por personal a cargo o localización directa
            en cama para monitorizar la venoclisis en tiempo real.
          </p>
        </div>

        <span className={styles.enLinea} aria-hidden="true" />

        <button
          type="button"
          className={styles.cerrar}
          onClick={onCerrar}
          aria-label="Cerrar el localizador"
        >
          <Icono nombre="cerrar" tamano={18} />
        </button>
      </header>

      <div className={styles.columnas}>
        {}
        <div className={styles.columna}>
          <div className={styles.seccionEncabezado}>
            <span className={styles.seccionIcono}>
              <Icono nombre="maletin" tamano={18} />
            </span>
            <div>
              <h3 className={styles.seccionTitulo}>Enfermera a Cargo</h3>
              <p className={styles.seccionNota}>
                Carga y pacientes bajo guardia activa
              </p>
            </div>
            <span className={styles.chipTurno}>Turno Matutino</span>
          </div>

          <p className={styles.rotulo}>Seleccionar personal en turno</p>

          <div className={styles.buscador}>
            <Icono nombre="buscar" tamano={15} />
            <input
              type="search"
              className={styles.buscadorCampo}
              placeholder="Buscar enfermera por nombre..."
              value={busquedaEnfermera}
              onChange={(evento) => setBusquedaEnfermera(evento.target.value)}
              aria-label="Buscar enfermera por nombre"
            />
          </div>

          {enfermerasVisibles.length === 0 ? (
            <p className={styles.vacio}>
              Ninguna enfermera coincide con la búsqueda.
            </p>
          ) : (
            <ul className={styles.rejillaEnfermeras}>
              {enfermerasVisibles.map((item) => {
                const indice = ENFERMERAS.findIndex((e) => e.id === item.id);
                const activa = item.id === enfermeraId;
                const carga = PACIENTES.filter(
                  (p) => p.enfermeraId === item.id
                ).length;

                return (
                  <li key={item.id}>
                    <button
                      type="button"
                      className={`${styles.enfermera} ${
                        activa ? styles.enfermeraActiva : ""
                      }`}
                      onClick={() => setEnfermeraId(item.id)}
                      aria-pressed={activa}
                    >
                      <span
                        className={styles.avatar}
                        data-color={indice % 6}
                        aria-hidden="true"
                      >
                        {item.iniciales}
                      </span>
                      <span className={styles.enfermeraTexto}>
                        <span className={styles.enfermeraNombre}>
                          {item.nombre}
                        </span>
                        <span className={styles.enfermeraCarga}>
                          {carga} {carga === 1 ? "Paciente" : "Pacientes"}
                        </span>
                      </span>
                    </button>
                  </li>
                );
              })}
            </ul>
          )}

          <div className={styles.asignadosEncabezado}>
            <h4 className={styles.asignadosTitulo}>
              <Icono nombre="verificado" tamano={15} />
              Pacientes asignados a {enfermera.nombre} ({asignados.length})
            </h4>
            <span className={styles.chipGuardia}>Guardia Activa</span>
          </div>

          <div className={styles.lista}>
            {asignados.length === 0 ? (
              <p className={styles.vacio}>
                Esta enfermera no tiene camas asignadas en el turno.
              </p>
            ) : (
              asignados.map((paciente) => (
                <TarjetaCama
                  key={paciente.id}
                  paciente={paciente}
                  seleccionado={paciente.id === pacienteId}
                  onSeleccionar={setPacienteId}
                  compacta
                />
              ))
            )}
          </div>
        </div>

        {}
        <div className={styles.columna}>
          <div className={styles.seccionEncabezado}>
            <span className={styles.seccionIcono}>
              <Icono nombre="buscar" tamano={18} />
            </span>
            <div>
              <h3 className={styles.seccionTitulo}>Búsqueda Directa</h3>
              <p className={styles.seccionNota}>
                Por nombre de paciente o número de cama
              </p>
            </div>
          </div>

          <div className={styles.buscador}>
            <Icono nombre="buscar" tamano={15} />
            <input
              type="search"
              className={styles.buscadorCampo}
              placeholder="Buscar por paciente (ej. Elena Rodríguez, Mateo..."
              value={busquedaPaciente}
              onChange={(evento) => setBusquedaPaciente(evento.target.value)}
              aria-label="Buscar por nombre de paciente o número de cama"
            />
          </div>

          <p className={styles.contador}>
            {resultados.length}{" "}
            {resultados.length === 1 ? "cama activa" : "camas activas"}
          </p>

          <div className={styles.lista}>
            {resultados.length === 0 ? (
              <p className={styles.vacio}>
                Ninguna cama coincide con la búsqueda.
              </p>
            ) : (
              resultados.map((paciente) => (
                <TarjetaCama
                  key={paciente.id}
                  paciente={paciente}
                  seleccionado={paciente.id === pacienteId}
                  onSeleccionar={setPacienteId}
                />
              ))
            )}
          </div>

          <aside className={styles.notaSensores}>
            <Icono nombre="senal" tamano={18} />
            <p>
              Los sensores ópticos de goteo <strong>DinoStar</strong> actualizan
              la tasa volumétrica cada 3 segundos.
            </p>
          </aside>
        </div>
      </div>

      <footer className={styles.pie}>
        <p className={styles.pieSeleccion}>
          {seleccionado ? (
            <>
              <Icono nombre="verificado" tamano={15} />
              Seleccionado actualmente:{" "}
              <strong>
                {seleccionado.nombre} ({seleccionado.cama})
              </strong>
            </>
          ) : (
            <>
              <Icono nombre="gota" tamano={15} />
              Selecciona una cama para continuar
            </>
          )}
        </p>

        <div className={styles.pieAcciones}>
          <button
            type="button"
            className={styles.botonCancelar}
            onClick={onCerrar}
          >
            Cancelar
          </button>
          <button
            type="button"
            className={styles.botonConfirmar}
            disabled={!seleccionado}
            onClick={() => onConfirmar?.(seleccionado)}
          >
            Ver Monitoreo de Infusión
          </button>
        </div>
      </footer>
    </section>
  );
}