import { useEffect, useState } from "react";
import { navigationItems } from "../config/navigation";
import Icono from "../components/Icono";
import styles from "./DashboardLayout.module.css";


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


export default function DashboardLayout({
  title = "Panel Principal",
  activeItem = "panel",
  user,
  ubicacion = "Ala Oeste · UCI",
  sincronizacion = "1s",
  badges = {},
  alertas = 0,
  onNavigate,
  onAlertas,
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

  return (
    <div className={styles.shell}>
      <aside className={styles.sidebar}>
        <div className={styles.brand}>
          <span className={styles.brandMark}>DinoStar</span>
          <span className={styles.brandSystem}>Sistema REX</span>
        </div>

        <p className={styles.systemStatus}>
          <span className={styles.statusDot} aria-hidden="true" />
          Sistema activo · en línea
        </p>

        <nav className={styles.nav} aria-label="Navegación principal">
          <ul>
            {navigationItems.map(({ id, label, to, badge }) => {
              const isActive = id === activeItem;
              const badgeValue = badge ? badges[badge] : undefined;

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
                    <span className={styles.navLabel}>{label}</span>
                    {badgeValue ? (
                      <span className={styles.navBadge}>{badgeValue}</span>
                    ) : null}
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
            onClick={onAlertas}
            aria-label={`Alertas activas: ${alertas}`}
          >
            <Icono nombre="campana" tamano={20} />
            {alertas > 0 && (
              <span className={styles.campanaBadge}>{alertas}</span>
            )}
          </button>

          <RelojSesion />

          {}
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

      <main className={styles.content}>{children}</main>
    </div>
  );
}