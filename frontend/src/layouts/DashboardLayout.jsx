import { useEffect, useState, createContext, useContext, useRef, useCallback } from "react";
import { navigationItems } from "../config/navigation";
import Icono from "../components/Icono";
import styles from "./DashboardLayout.module.css";
import logoDinoStar from "../assets/Logo.png";
import AlertaBanner from "../components/AlertaBanner";
import echo from "../echo";
import { getPacientes } from "../api/pacientes";
import { analizarVolumen, numeroONulo } from "../utils/volumen";

const DashboardContext = createContext();
export const useDashboardContext = () => useContext(DashboardContext);

const FORMATO_FECHA = new Intl.DateTimeFormat("es-MX", {
  day: "2-digit",
  month: "short",
  year: "numeric",
});

const FORMATO_HORA = new Intl.DateTimeFormat("es-MX", {
  hour: "2-digit",
  minute: "2-digit",
  hour12: true,
});

function RelojSesion() {
  const [ahora, setAhora] = useState(() => new Date());

  useEffect(() => {
    const id = setInterval(() => setAhora(new Date()), 1000);
    return () => clearInterval(id);
  }, []);

  const fecha = FORMATO_FECHA.format(ahora).replace(".", "");
  const hora = FORMATO_HORA.format(ahora).toUpperCase();

  return (
    <div className={styles.reloj}>
      <Icono nombre="calendario" tamano={16} />
      <time dateTime={ahora.toISOString()}>
        {fecha} · {hora}
      </time>
    </div>
  );
}

const FACTOR_GOTEO = {
  NORMAL_GOTEO: 20,
  MICRO_GOTEO: 60,
};

function normalizarCama(valor) {
  return String(valor ?? "").trim().toLowerCase();
}

function factorGoteo(modo) {
  return FACTOR_GOTEO[modo] ?? FACTOR_GOTEO.NORMAL_GOTEO;
}

function calcularFlujoMlH(gotasPorMin, modo) {
  if (gotasPorMin === null) return null;
  return (gotasPorMin * 60) / factorGoteo(modo);
}

function redondearFlujo(flujo) {
  if (flujo === null) return null;
  return Math.round(flujo * 10) / 10;
}

function formatearTiempoRestante(minutos) {
  const total = numeroONulo(minutos);
  if (total === null || total < 0) return null;

  const horas = Math.floor(total / 60);
  const mins = Math.round(total % 60);
  return `Restan est. ${horas}h ${mins}m`;
}

function formatearHora(timestamp) {
  const milisegundos = Number(timestamp);
  const fecha =
    Number.isFinite(milisegundos) && milisegundos > 0
      ? new Date(milisegundos)
      : new Date();

  return fecha.toLocaleTimeString([], {
    hour: "2-digit",
    minute: "2-digit",
    hour12: false,
  });
}

function calcularPorcentaje(volRestante, volTotal) {
  return analizarVolumen(volRestante, volTotal).porcentaje;
}

function etiquetaRitmo(modoGoteo) {
  return modoGoteo === "MICRO_GOTEO" ? "Microgoteo" : "Macrogoteo";
}

function etiquetaBajante(modoGoteo) {
  return modoGoteo === "MICRO_GOTEO"
    ? "MICRO (60 GTT/ML)"
    : "NORMAL (20 GTT/ML)";
}

function calcularEstadoTarjeta(gotasPorMin, volRestante) {
  if (volRestante !== null && volRestante < 50) {
    return {
      estado: "alerta",
      textoEstado: "ALERTA DE INFUSIÓN",
      tipoAlerta: "FIN_BOLSA",
    };
  }
  if (gotasPorMin !== null && gotasPorMin < 20) {
    return {
      estado: "alerta",
      textoEstado: "ALERTA DE INFUSIÓN",
      tipoAlerta: "GOTEO_LENTO",
    };
  }
  if (gotasPorMin !== null && gotasPorMin > 60) {
    return {
      estado: "alerta",
      textoEstado: "ALERTA DE INFUSIÓN",
      tipoAlerta: "GOTEO_RAPIDO",
    };
  }
  if (volRestante !== null && volRestante < 100) {
    return { estado: "atencion", textoEstado: "ATENCIÓN REQUERIDA", tipoAlerta: null };
  }

  return { estado: "normal", textoEstado: "GOTEO REGULAR", tipoAlerta: null };
}

function crearPacienteDesdeApi(pacienteDb) {
  const volTotal = numeroONulo(pacienteDb.vol_total);
  const modoGoteo = pacienteDb.modo_goteo;

  return {
    id: pacienteDb.id,
    alertaId: pacienteDb.id,
    cama: pacienteDb.numero_cama,
    pacienteId: pacienteDb.numero_cama,
    nombre: pacienteDb.nombre,
    sala: pacienteDb.sala,
    idPaciente: `${pacienteDb.sala} • ID: ${pacienteDb.id}`,
    enfermeraId: pacienteDb.enfermera_id,

    gotasPorMin: null,
    flujoActual: null,
    volRestante: null,
    tiempoRestante: null,
    porcentajeVolumen: 0,

    estadoRitmo: etiquetaRitmo(modoGoteo),
    metaFlujo: null,
    volTotal,
    estado: "normal",
    textoEstado: "ESPERANDO PRIMERA LECTURA",

    nombreSolucion: pacienteDb.tipo_solucion || "Solución sin registrar",
    tipoBajante: etiquetaBajante(modoGoteo),
    modoGoteo,
    tipoAlerta: null,
    horaAlerta: null,
  };
}

function crearPacienteDesdePaquete(pacienteDb, packet) {
  const gotasPorMin = numeroONulo(packet.gotasPorMin);
  const volRestante = numeroONulo(packet.volRestante);
  const volTotal = numeroONulo(pacienteDb.vol_total);
  const modoGoteo = packet.modo ?? pacienteDb.modo_goteo;
  const infoEstado = calcularEstadoTarjeta(gotasPorMin, volRestante);

  return {
    id: pacienteDb.id,
    alertaId: packet.alerta_id ?? packet.alertaId ?? pacienteDb.id, // ID real de la alerta
    cama: pacienteDb.numero_cama,
    pacienteId: pacienteDb.numero_cama,
    nombre: pacienteDb.nombre,
    sala: pacienteDb.sala,
    idPaciente: `${pacienteDb.sala} • ID: ${pacienteDb.id}`,
    enfermeraId: pacienteDb.enfermera_id,
    gotasPorMin,
    flujoActual: redondearFlujo(calcularFlujoMlH(gotasPorMin, modoGoteo)),
    estadoRitmo: etiquetaRitmo(modoGoteo),
    metaFlujo: null,
    porcentajeVolumen: calcularPorcentaje(volRestante, volTotal),
    volRestante,
    volTotal,
    tiempoRestante: formatearTiempoRestante(packet.tiempoRestante),
    estado: infoEstado.estado,
    textoEstado: infoEstado.textoEstado,
    nombreSolucion: pacienteDb.tipo_solucion || "Solución sin registrar",
    tipoBajante: etiquetaBajante(modoGoteo),
    modoGoteo,
    tipoAlerta: infoEstado.tipoAlerta,
    horaAlerta: infoEstado.tipoAlerta ? formatearHora(packet.timestamp) : null,
  };
}

function actualizarPacienteConPaquete(paciente, packet) {
  const gotasPorMin = numeroONulo(packet.gotasPorMin);
  const volRestante = numeroONulo(packet.volRestante);
  const volTotal = numeroONulo(paciente.volTotal);
  const modoGoteo = packet.modo ?? paciente.modoGoteo;
  const infoEstado = calcularEstadoTarjeta(gotasPorMin, volRestante);

  return {
    ...paciente,
    gotasPorMin,
    flujoActual: redondearFlujo(calcularFlujoMlH(gotasPorMin, modoGoteo)),
    estadoRitmo: etiquetaRitmo(modoGoteo),
    modoGoteo,
    tipoBajante: etiquetaBajante(modoGoteo),
    porcentajeVolumen: calcularPorcentaje(volRestante, volTotal),
    volRestante,
    tiempoRestante: formatearTiempoRestante(packet.tiempoRestante),
    estado: infoEstado.estado,
    textoEstado: infoEstado.textoEstado,
    tipoAlerta: infoEstado.tipoAlerta,
    horaAlerta: infoEstado.tipoAlerta
      ? formatearHora(packet.timestamp)
      : paciente.horaAlerta,
  };
}

// Tarjeta inicial de un paciente que todavía no ha mandado lecturas.
// Se reemplaza por datos reales cuando llega su primer paquete.
function crearTarjetaBase(pacienteDb) {
  const volTotal = Number(pacienteDb.vol_total) || 0;
  const modoGoteo = pacienteDb.modo_goteo;

  return {
    id: pacienteDb.id,
    alertaId: pacienteDb.id,
    cama: pacienteDb.numero_cama,
    pacienteId: pacienteDb.numero_cama,
    nombre: pacienteDb.nombre,
    sala: pacienteDb.sala,
    idPaciente: `${pacienteDb.sala} • ID: ${pacienteDb.id}`,
    enfermeraId: pacienteDb.enfermera_id,
    gotasPorMin: 0,
    flujoActual: 0,
    estadoRitmo: modoGoteo === "MICRO_GOTEO" ? "Microgoteo" : "Macrogoteo",
    metaFlujo: null,
    porcentajeVolumen: 100,
    volRestante: volTotal,
    volTotal,
    tiempoRestante: "Sin lecturas aún",
    estado: "normal",
    textoEstado: "ESPERANDO LECTURAS",
    nombreSolucion: pacienteDb.tipo_solucion || "Solución sin registrar",
    tipoBajante:
      modoGoteo === "MICRO_GOTEO" ? "MICRO (60 GTT/ML)" : "NORMAL (20 GTT/ML)",
    modoGoteo,
    tipoAlerta: null,
    horaAlerta: null,
  };
}

// Copia a una tarjeta existente los datos "fijos" del paciente (los que
// vienen de la BD), sin tocar las lecturas en vivo.
function aplicarDatosDelCatalogo(tarjeta, pacienteDb) {
  return {
    ...tarjeta,
    cama: pacienteDb.numero_cama,
    pacienteId: pacienteDb.numero_cama,
    nombre: pacienteDb.nombre,
    sala: pacienteDb.sala,
    idPaciente: `${pacienteDb.sala} • ID: ${pacienteDb.id}`,
    enfermeraId: pacienteDb.enfermera_id,
    nombreSolucion: pacienteDb.tipo_solucion || "Solución sin registrar",
  };
}

// Alinea las tarjetas con el catálogo de la BD:
// - actualiza nombre, cama y sala de las que ya existen,
// - quita las de pacientes dados de baja,
// - crea la tarjeta de los pacientes nuevos.
function sincronizarTarjetas(tarjetas, catalogo) {
  const porId = new Map(catalogo.map((p) => [p.id, p]));
  const conTarjeta = new Set();
  const resultado = [];

  tarjetas.forEach((tarjeta) => {
    const pacienteDb = porId.get(tarjeta.id);
    if (!pacienteDb) return;
    conTarjeta.add(tarjeta.id);
    resultado.push(aplicarDatosDelCatalogo(tarjeta, pacienteDb));
  });

  catalogo.forEach((pacienteDb) => {
    if (!conTarjeta.has(pacienteDb.id)) {
      resultado.push(crearTarjetaBase(pacienteDb));
    }
  });

  return resultado;
}

export default function DashboardLayout({
  title = "Panel Principal",
  activeItem = "panel",
  user,
  ubicacion = "Ala Oeste · UCI",
  sincronizacion = "1s",
  onNavigate,
  onLogout,
  children,
}) {
  const enfermera = user ?? {
    nombre: "Sin sesión",
    estado: "—",
    unidad: "—",
  };

  const iniciales =
    enfermera.iniciales ??
    enfermera.nombre
      .split(" ")
      .filter((parte) => parte.length > 1 && !parte.includes("."))
      .slice(-2)
      .map((parte) => parte[0])
      .join("");


  const [pacientes, setPacientes] = useState([]);
  // Pacientes tal como los guarda la BD (incluye los que aún no tienen lecturas)
  const [catalogo, setCatalogo] = useState([]);
  const [cargandoPacientes, setCargandoPacientes] = useState(true);
  const [errorPacientes, setErrorPacientes] = useState(null);

  const [wsConectado, setWsConectado] = useState(() => {
    const conexion = echo.connector?.pusher?.connection;
    return conexion ? conexion.state === "connected" : true;
  });
  const [mostrarBanner, setMostrarBanner] = useState(false);
  const [triggerAlerta, setTriggerAlerta] = useState(0);

  const catalogoRef = useRef([]);
  const catalogoListoRef = useRef(false);
  const pendientesRef = useRef([]);

  // Guarda el catálogo (para validar camas de los paquetes) y deja las
  // tarjetas en pantalla alineadas con él.
  const aplicarCatalogo = useCallback((lista) => {
    catalogoRef.current = lista;
    setCatalogo(lista);
    setPacientes((prev) => sincronizarTarjetas(prev, lista));
  }, []);

  // Vuelve a pedir la lista al servidor. Se llama después de crear,
  // editar o dar de baja, para que la pantalla refleje la BD al instante.
  const refrescarPacientes = useCallback(async () => {
    try {
      const lista = await getPacientes();
      aplicarCatalogo(Array.isArray(lista) ? lista : []);
    } catch (error) {
      console.error("Error al refrescar pacientes:", error);
    }
  }, [aplicarCatalogo]);

  const alertasActivas = pacientes.filter((p) => p.estado === "alerta");
  const cantidadAlertas = alertasActivas.length;
  const prevCantidadRef = useRef(cantidadAlertas);
  useEffect(() => {
    if (cantidadAlertas > 0 && cantidadAlertas > prevCantidadRef.current) {
      setMostrarBanner(true);
    }
    prevCantidadRef.current = cantidadAlertas;
  }, [cantidadAlertas]);


  useEffect(() => {
    let activo = true;
    catalogoListoRef.current = false;

    const procesarPaquete = (packet) => {
      if (!packet || typeof packet !== "object") return;

      const camaRecibida = normalizarCama(packet.pacienteId ?? packet.cama);
      if (!camaRecibida) return;

      const pacienteEnDb = catalogoRef.current.find(
        (paciente) => normalizarCama(paciente.numero_cama) === camaRecibida
      );

      if (!pacienteEnDb) return;

      setPacientes((prevPacientes) => {
        const indice = prevPacientes.findIndex(
          (paciente) => normalizarCama(paciente.cama) === camaRecibida
        );

        if (indice === -1) {
          return [
            ...prevPacientes,
            crearPacienteDesdePaquete(pacienteEnDb, packet),
          ];
        }

        const actualizados = [...prevPacientes];
        actualizados[indice] = actualizarPacienteConPaquete(
          actualizados[indice],
          packet
        );
        return actualizados;
      });
    };

    async function cargarCatalogoPacientes() {
      try {
        const pacientesDb = await getPacientes();
        if (!activo) return;

        aplicarCatalogo(Array.isArray(pacientesDb) ? pacientesDb : []);
        catalogoListoRef.current = true;
        setPacientes(catalogoRef.current.map(crearPacienteDesdeApi));
        setErrorPacientes(null);
        setCargandoPacientes(false);
        const pendientes = pendientesRef.current;
        pendientesRef.current = [];
        pendientes.forEach(procesarPaquete);

      } catch (error) {
        if (!activo) return;

        catalogoRef.current = [];
        catalogoListoRef.current = true;
        pendientesRef.current = [];
        setPacientes([]);
        setErrorPacientes(error);
        setCargandoPacientes(false);
        console.error("Error al cargar el catálogo de pacientes:", error);
      }
    }

    cargarCatalogoPacientes();


    const pusher = echo.connector?.pusher;
    const alConectar = () => setWsConectado(true);
    const alDesconectar = () => setWsConectado(false);
    const alConectando = () => setWsConectado(false);

    if (pusher) {
      pusher.connection.bind("connected", alConectar);
      pusher.connection.bind("disconnected", alDesconectar);
      pusher.connection.bind("connecting", alConectando);
    }

    const channel = echo.channel("pacientes");
    const alRecibirLectura = (e) => {
      const rawData = e.datapacket || e.data || e;
      const packetsList = Array.isArray(rawData) ? rawData : [rawData];

      packetsList.forEach((packet) => {
        if (!catalogoListoRef.current) {
          pendientesRef.current.push(packet);
          return;
        }
        procesarPaquete(packet);
      });
    };

    channel.listen(".nueva.lectura", alRecibirLectura);

    return () => {
      activo = false;
      channel.stopListening(".nueva.lectura", alRecibirLectura);
      echo.leave("pacientes");

      if (pusher) {
        pusher.connection.unbind("connected", alConectar);
        pusher.connection.unbind("disconnected", alDesconectar);
        pusher.connection.unbind("connecting", alConectando);
      }
    };
  }, []);

  // Formato que usa el modal de alta para listar y editar pacientes
  const catalogoPacientes = catalogo.map((p) => ({
    id: p.id,
    nombre: p.nombre,
    cama: p.numero_cama,
    sala: p.sala,
    volTotal: Number(p.vol_total),
    modoGoteo: p.modo_goteo,
    solucion: p.tipo_solucion ?? "Sin solución registrada",
    enfermeraId: p.enfermera_id,
  }));

  return (
    <div className={styles.shell}>
      <aside className={styles.sidebar}>
        <div className={styles.brand}>
          <img src={logoDinoStar} alt="DinoStar" className={styles.brandLogo} />
        </div>

        <nav className={styles.nav} aria-label="Navegación principal">
          <ul>
            {navigationItems.map(({ id, label, to, icono }) => {
              const isActive = id === activeItem;
              return (
                <li key={id}>
                  <button
                    type="button"
                    className={`${styles.navLink} ${isActive ? styles.navLinkActive : ""
                      }`}
                    aria-current={isActive ? "page" : undefined}
                    onClick={() => onNavigate?.(id, to)}
                  >
                    <Icono nombre={icono} tamano={18} className={styles.navIcono} />
                    <span className={styles.navLabel}>{label}</span>
                  </button>
                </li>
              );
            })}
          </ul>
        </nav>

        <p className={styles.version}>DinoStar Server v1.0</p>
      </aside>

      <header className={styles.header}>
        <div className={styles.headerIzquierda}>
          <h1 className={styles.viewTitle}>{title}</h1>

          <span className={`${styles.chip} ${styles.chipUbicacion}`}>
            <Icono nombre="ubicacion" tamano={14} />
            {ubicacion}
          </span>

          <span className={`${styles.chip} ${styles.chipSync}`}>
            <Icono nombre="sincronizar" tamano={14} />
            Sincronización: {sincronizacion}
          </span>

          {}
          <span
            className={`${styles.chip} ${wsConectado ? styles.chipWsConectado : styles.chipWsCaido}`}
            style={{
              backgroundColor: wsConectado ? '#d1fae5' : '#fee2e2',
              color: wsConectado ? '#065f46' : '#991b1b',
              border: `1px solid ${wsConectado ? '#10b981' : '#ef4444'}`,
              display: 'inline-flex',
              alignItems: 'center',
              gap: '6px'
            }}
          >
            <span style={{
              width: '8px',
              height: '8px',
              borderRadius: '50%',
              backgroundColor: wsConectado ? '#10b981' : '#ef4444',
              display: 'inline-block'
            }}></span>
            WebSocket: {wsConectado ? "Conectado" : "Desconectado"}
          </span>
        </div>

        <div className={styles.headerDerecha}>
          <button
            type="button"
            className={`${styles.campana} ${cantidadAlertas > 0 ? styles.pulsando : ''}`}
            onClick={() => {
              setMostrarBanner(false);
              setTriggerAlerta((prev) => prev + 1);
              onNavigate?.("panel", "/dashboard");
            }}
            aria-label={`Alertas activas: ${cantidadAlertas}`}
          >
            <Icono nombre="campana" tamano={20} />
            {cantidadAlertas > 0 && (
              <span className={styles.campanaBadge}>{cantidadAlertas}</span>
            )}
          </button>

          <RelojSesion />

          <button
            type="button"
            className={styles.sesion}
            onClick={onLogout}
            title="Cerrar sesión"
          >
            <span className={styles.sesionTexto}>
              <span className={styles.sesionNombre}>{enfermera.nombre}</span>
              <span className={styles.sesionMeta}>
                {enfermera.estado} · {enfermera.unidad}
              </span>
            </span>
            <span className={styles.avatar} aria-hidden="true">
              {iniciales}
            </span>
          </button>
        </div>
      </header>

      {}
      {mostrarBanner && (
        <AlertaBanner
          alertas={alertasActivas}
          enfermeraId={enfermera?.id ?? 1} 
          onClose={() => setMostrarBanner(false)}
          onAlertaResuelta={(idAlertaResuelta) => {
          setPacientes((prevPacientes) =>
          prevPacientes.map((p) =>
            p.id === idAlertaResuelta ? { ...p, estado: "normal", tipoAlerta: null } : p
        )
      );
    }}
  />
      )}

      <main className={styles.content}>
        <DashboardContext.Provider
          value={{
            pacientes,
            setPacientes,
            triggerAlerta,
            enfermera,
            catalogoPacientes,
            refrescarPacientes,
            cargandoPacientes,
            errorPacientes,
          }}
        >
          {children}
        </DashboardContext.Provider>
      </main>
    </div>
  );
}