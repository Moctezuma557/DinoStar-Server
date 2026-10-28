import { useEffect, useState, createContext, useContext, useRef } from "react";
import { navigationItems } from "../config/navigation";
import Icono from "../components/Icono";
import styles from "./DashboardLayout.module.css";
import logoDinoStar from "../assets/Logo.png";
import AlertaBanner from "../components/AlertaBanner";
import echo from "../echo";
import { getPacientes } from "../api/pacientes";

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
  return (gotasPorMin * 60) / factorGoteo(modo);
}

function formatearTiempoRestante(minutos) {
  const total = Math.max(0, Number(minutos) || 0);
  const horas = Math.floor(total / 60);
  const mins = total % 60;
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
  if (!Number.isFinite(volTotal) || volTotal <= 0) return 0;

  const porcentaje = Math.round((volRestante / volTotal) * 100);
  return Math.min(100, Math.max(0, porcentaje));
}

function calcularEstadoTarjeta(gotasPorMin, volRestante) {
  if (volRestante < 50) {
    return {
      estado: "alerta",
      textoEstado: "ALERTA DE INFUSIÓN",
      tipoAlerta: "FIN_BOLSA",
    };
  }
  if (gotasPorMin < 20) {
    return {
      estado: "alerta",
      textoEstado: "ALERTA DE INFUSIÓN",
      tipoAlerta: "GOTEO_LENTO",
    };
  }
  if (gotasPorMin > 60) {
    return {
      estado: "alerta",
      textoEstado: "ALERTA DE INFUSIÓN",
      tipoAlerta: "GOTEO_RAPIDO",
    };
  }
  if (volRestante < 100) {
    return { estado: "atencion", textoEstado: "ATENCIÓN REQUERIDA", tipoAlerta: null };
  }

  return { estado: "normal", textoEstado: "GOTEO REGULAR", tipoAlerta: null };
}

function crearPacienteDesdePaquete(pacienteDb, packet) {
  const gotasPorMin = Number(packet.gotasPorMin) || 0;
  const volRestante = Number(packet.volRestante) || 0;
  const tiempoRestante = Number(packet.tiempoRestante) || 0;
  const volTotal = Number(pacienteDb.vol_total) || 0;
  const modoGoteo = packet.modo ?? pacienteDb.modo_goteo;
  const infoEstado = calcularEstadoTarjeta(gotasPorMin, volRestante);

  return {
    id: pacienteDb.id,
    cama: pacienteDb.numero_cama,
    pacienteId: pacienteDb.numero_cama,
    nombre: pacienteDb.nombre,
    sala: pacienteDb.sala,
    idPaciente: `${pacienteDb.sala} • ID: ${pacienteDb.id}`,
    enfermeraId: pacienteDb.enfermera_id,
    gotasPorMin,
    flujoActual: Math.round(calcularFlujoMlH(gotasPorMin, modoGoteo) * 10) / 10,
    estadoRitmo: modoGoteo === "MICRO_GOTEO" ? "Microgoteo" : "Macrogoteo",
    metaFlujo: null,
    porcentajeVolumen: calcularPorcentaje(volRestante, volTotal),
    volRestante,
    volTotal,
    tiempoRestante: formatearTiempoRestante(tiempoRestante),
    estado: infoEstado.estado,
    textoEstado: infoEstado.textoEstado,
    nombreSolucion: pacienteDb.tipo_solucion || "Solución sin registrar",
    tipoBajante:
      modoGoteo === "MICRO_GOTEO" ? "MICRO (60 GTT/ML)" : "NORMAL (20 GTT/ML)",
    modoGoteo,
    tipoAlerta: infoEstado.tipoAlerta,
    horaAlerta: infoEstado.tipoAlerta ? formatearHora(packet.timestamp) : null,
  };
}

function actualizarPacienteConPaquete(paciente, packet) {
  const gotasPorMin = Number(packet.gotasPorMin) || 0;
  const volRestante = Number(packet.volRestante) || 0;
  const tiempoRestante = Number(packet.tiempoRestante) || 0;
  const volTotal = Number(paciente.volTotal) || 0;
  const modoGoteo = packet.modo ?? paciente.modoGoteo;
  const infoEstado = calcularEstadoTarjeta(gotasPorMin, volRestante);

  return {
    ...paciente,
    gotasPorMin,
    flujoActual: Math.round(calcularFlujoMlH(gotasPorMin, modoGoteo) * 10) / 10,
    estadoRitmo: modoGoteo === "MICRO_GOTEO" ? "Microgoteo" : "Macrogoteo",
    modoGoteo,
    tipoBajante:
      modoGoteo === "MICRO_GOTEO" ? "MICRO (60 GTT/ML)" : "NORMAL (20 GTT/ML)",
    porcentajeVolumen: calcularPorcentaje(volRestante, volTotal),
    volRestante,
    tiempoRestante: formatearTiempoRestante(tiempoRestante),
    estado: infoEstado.estado,
    textoEstado: infoEstado.textoEstado,
    tipoAlerta: infoEstado.tipoAlerta,
    horaAlerta: infoEstado.tipoAlerta
      ? formatearHora(packet.timestamp)
      : paciente.horaAlerta,
  };
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

  // Estado global de pacientes
  const [pacientes, setPacientes] = useState([]);
  const [wsConectado, setWsConectado] = useState(() => {
    const conexion = echo.connector?.pusher?.connection;
    return conexion ? conexion.state === "connected" : true;
  });
  const [mostrarBanner, setMostrarBanner] = useState(false);
  const [triggerAlerta, setTriggerAlerta] = useState(0);

  const catalogoRef = useRef([]);
  const catalogoListoRef = useRef(false);
  const pendientesRef = useRef([]);

  const alertasActivas = pacientes.filter((p) => p.estado === "alerta");
  const cantidadAlertas = alertasActivas.length;

  // Referencia para rastrear si aumentó el número de alertas y abrir el banner automáticamente
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

      // Solo se crean tarjetas para camas registradas en la BD.
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

        catalogoRef.current = Array.isArray(pacientesDb) ? pacientesDb : [];
        catalogoListoRef.current = true;

        // Procesar paquetes que llegaron mientras se cargaba el catálogo.
        const pendientes = pendientesRef.current;
        pendientesRef.current = [];
        pendientes.forEach(procesarPaquete);
      } catch (error) {
        if (!activo) return;

        // Sin catálogo no se puede validar la cama; se descartan los paquetes
        // para no acumularlos indefinidamente.
        catalogoRef.current = [];
        catalogoListoRef.current = true;
        pendientesRef.current = [];
        console.error("Error al cargar el catálogo de pacientes:", error);
      }
    }

    cargarCatalogoPacientes();

    // Configuración y monitoreo de conexión de Laravel Reverb (WebSocket)
    const pusher = echo.connector?.pusher;
    const alConectar = () => setWsConectado(true);
    const alDesconectar = () => setWsConectado(false);
    const alConectando = () => setWsConectado(false);

    if (pusher) {
      pusher.connection.bind("connected", alConectar);
      pusher.connection.bind("disconnected", alDesconectar);
      pusher.connection.bind("connecting", alConectando);
    }

    // Las tarjetas nacen con el primer paquete de una cama registrada en la BD.
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

          {/* Indicador visual de conexión WebSocket en la parte superior */}
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
            className={styles.campana}
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

      {/* Banner flotante renderizado a nivel global del Layout */}
      {mostrarBanner && (
        <AlertaBanner
          alertas={alertasActivas}
          onClose={() => setMostrarBanner(false)}
          onViewDashboard={() => {
            setMostrarBanner(false);
            setTriggerAlerta((prev) => prev + 1);
            onNavigate?.("panel", "/dashboard");
          }}
        />
      )}

      <main className={styles.content}>
        <DashboardContext.Provider value={{ pacientes, setPacientes, triggerAlerta }}>
          {children}
        </DashboardContext.Provider>
      </main>
    </div>
  );
}