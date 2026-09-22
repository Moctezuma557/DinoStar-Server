import { navigationItems } from "../config/navigation";
import styles from "./DashboardLayout.module.css";

export default function DashboardLayout({
  title = "Panel Principal",
  activeItem = "panel",
  user,
  badges = {},
  onNavigate,
  onLogout,
  children,
}) {
  const enfermera = user ?? {
    nombre: "Sin sesión",
    turno: "—",
    unidad: "—",
  };

  return (
    <div className={styles.shell}>
      <aside className={styles.sidebar}>
        <div className={styles.brand}>
          {/* Cuando el logo SVG esté en la carpeta compartida,
              se reemplaza este bloque por <img src={logo} />. */}
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
        <h1 className={styles.viewTitle}>{title}</h1>

        <div className={styles.session}>
          <div className={styles.sessionText}>
            <span className={styles.sessionName}>{enfermera.nombre}</span>
            <span className={styles.sessionMeta}>
              Turno {enfermera.turno} · {enfermera.unidad}
            </span>
          </div>

          <button type="button" className={styles.logout} onClick={onLogout}>
            Cerrar sesión
          </button>
        </div>
      </header>

      <main className={styles.content}>{children}</main>
    </div>
  );
}