import { useEffect, useState, createContext, useContext, useRef } from "react";
import { navigationItems } from "../config/navigation";
import Icono from "../components/Icono";
import styles from "./DashboardLayout.module.css";
import logoDinoStar from "../assets/Logo.png";
import AlertaBanner from "../components/AlertaBanner";
import echo from "../echo";

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

function determinarTipoAlerta(gotasPorMin, volRestante) {
  if (volRestante <= 50) return "FIN_BOLSA";
  if (gotasPorMin < 15) return "GOTEO_LENTO";
  if (gotasPorMin > 80) return "GOTEO_RAPIDO";
  return null;
}

function calcularEstadoTarjeta(gotasPorMin, volRestante) {
  const tipoAlerta = determinarTipoAlerta(gotasPorMin, volRestante);
  if (tipoAlerta) {
    return { estado: "alerta", textoEstado: "ALERTA DE INFUSIÓN", tipoAlerta };
  }
  if (gotasPorMin < 20 || gotasPorMin > 60 || volRestante < 100) {
    return { estado: "atencion", textoEstado: "ATENCIÓN REQUERIDA", tipoAlerta: null };
  }
  return { estado: "normal", textoEstado: "GOTEO REGULAR", tipoAlerta: null };
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

  const [pacientes, setPacientes] = useState([
    {
      id: 1,
      cama: "cama-01",
      pacienteId: "cama-01",
      nombre: "Valeria Pérez",
      edad: 22,
      idPaciente: "Habitación 405-A • ID: 9104",
      gotasPorMin: 45,
      estadoRitmo: "Constante",
      flujoActual: 45,
      metaFlujo: 60,
      porcentajeVolumen: 60,
      volRestante: 300,
      volTotal: 500,
      tiempoRestante: "Restan est. 2h 00m",
      estado: "normal",
      textoEstado: "GOTEO REGULAR",
      nombreSolucion: "Solución Salina 0.9%",
      tipoBajante: "MICRO (60 GTT/ML)",
    },
  ]);

  const [mostrarBanner, setMostrarBanner] = useState(false);
  const [triggerAlerta, setTriggerAlerta] = useState(0);

  const alertasActivas = pacientes.filter((p) => p.estado === "alerta");
  const cantidadAlertas = alertasActivas.length;

  // Referencia para rastrear si aumentó el número de alertas y abrir el banner automáticamente
  const prevCantidadRef = useRef(cantidadAlertas);
  useEffect(() => {
    if (cantidadAlertas > 0 && cantidadAlertas > prevCantidadRef.current) {
      setMostrarBanner(true); // <--- Se abre solo al recibir una nueva alerta
    }
    prevCantidadRef.current = cantidadAlertas;
  }, [cantidadAlertas]);

  // Escucha del WebSocket
  useEffect(() => {
    const channel = echo.channel("pacientes");

    channel.listen(".nueva.lectura", (e) => {
      const rawData = e.datapacket || e.data || e;
      const packetsList = Array.isArray(rawData) ? rawData : [rawData];

      setPacientes((prevPacientes) => {
        let updatedPacientes = [...prevPacientes];

        packetsList.forEach((packet) => {
          const camaRecibida = packet.pacienteId || packet.cama;
          if (!camaRecibida) return;

          const index = updatedPacientes.findIndex(
            (p) => p.cama === camaRecibida || p.pacienteId === camaRecibida
          );

          const gotas = Number(packet.gotasPorMin) || 0;
          const volumen = Number(packet.volRestante) || 0;
          const tiempoMinutos = Number(packet.tiempoRestante) || 0;
          const horas = Math.floor(tiempoMinutos / 60);
          const mins = tiempoMinutos % 60;
          const textoTiempo = `Restan est. ${horas}h ${mins}m`;

          const infoEstado = calcularEstadoTarjeta(gotas, volumen);
          const horaActual = new Date().toLocaleTimeString([], { hour: "2-digit", minute: "2-digit", hour12: false });

          if (index !== -1) {
            updatedPacientes[index] = {
              ...updatedPacientes[index],
              gotasPorMin: gotas,
              volRestante: volumen,
              porcentajeVolumen: Math.round((volumen / 500) * 100),
              tiempoRestante: textoTiempo,
              estado: infoEstado.estado,
              textoEstado: infoEstado.textoEstado,
              tipoAlerta: infoEstado.tipoAlerta,
              horaAlerta: infoEstado.tipoAlerta ? horaActual : updatedPacientes[index].horaAlerta,
            };
          } else {
            updatedPacientes.push({
              id: Date.now() + Math.random(),
              cama: camaRecibida,
              pacienteId: camaRecibida,
              nombre: `Paciente Cama ${camaRecibida}`,
              edad: 28,
              idPaciente: `Habitación • ID: ${camaRecibida}`,
              gotasPorMin: gotas,
              estadoRitmo: packet.modo || "Constante",
              flujoActual: gotas,
              metaFlujo: 60,
              porcentajeVolumen: Math.round((volumen / 500) * 100),
              volRestante: volumen,
              volTotal: 500,
              tiempoRestante: textoTiempo,
              estado: infoEstado.estado,
              textoEstado: infoEstado.textoEstado,
              nombreSolucion: "Solución Salina 0.9%",
              tipoBajante: "MICRO (60 GTT/ML)",
              tipoAlerta: infoEstado.tipoAlerta,
              horaAlerta: infoEstado.tipoAlerta ? horaActual : null,
            });
          }
        });

        return updatedPacientes;
      });
    });

    return () => {
      echo.leave("pacientes");
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
                    className={`${styles.navLink} ${
                      isActive ? styles.navLinkActive : ""
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
        </div>

        <div className={styles.headerDerecha}>
          <button
  type="button"
  className={styles.campana}
  onClick={() => {
    setMostrarBanner(false);                     // Nos aseguramos de que el banner no se abra
    setTriggerAlerta((prev) => prev + 1);        // Disparamos la señal para que el Dashboard seleccione "Solo Alertas"
    onNavigate?.("panel", "/dashboard");         // Redirigimos al Panel Principal / Dashboard
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