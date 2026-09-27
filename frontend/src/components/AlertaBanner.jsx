import styles from "./AlertaBanner.module.css";

const MENSAJES_POR_TIPO = {
  FIN_BOLSA: (cama) => `La bolsa de ${cama} está por terminarse`,
  GOTEO_LENTO: (cama) => `Goteo lento detectado en ${cama}`,
  GOTEO_RAPIDO: (cama) => `Goteo rápido detectado en ${cama}`,
  DESCONECTADO: (cama) => `Sin señal del dispositivo en ${cama}`,
};
const MENSAJE_DESCONOCIDO = (cama) => `Alerta sin clasificar en ${cama}`;

/**
 * Props:
 *   paciente   · nombre del paciente
 *   cama       · número o identificador de cama
 *   tipo       · FIN_BOLSA | GOTEO_LENTO | GOTEO_RAPIDO | DESCONECTADO
 *   hora       · hora de generación, formato HH:MM
 *   onAtender  · callback al presionar "Marcar como atendida"
 */
export default function AlertaBanner({
  paciente,
  cama,
  tipo,
  hora,
  onAtender,
}) {
  const construirMensaje = MENSAJES_POR_TIPO[tipo] ?? MENSAJE_DESCONOCIDO;
  const mensaje = construirMensaje(cama);

  return (
    <article className={styles.banner} role="alert">
      <span className={styles.icono} aria-hidden="true">
        <svg viewBox="0 0 24 24" width="22" height="22" fill="currentColor">
          <path d="M12 2 1 21h22L12 2Zm0 6a1 1 0 0 1 1 1v5a1 1 0 0 1-2 0V9a1 1 0 0 1 1-1Zm0 9.5a1.25 1.25 0 1 1 0 2.5 1.25 1.25 0 0 1 0-2.5Z" />
        </svg>
      </span>

      <div className={styles.contenido}>
        <p className={styles.encabezado}>
          {}
          <span className={styles.etiqueta}>Alerta</span>
          <span className={styles.paciente}>{paciente}</span>
          <span className={styles.cama}>{cama}</span>
        </p>
        <p className={styles.mensaje}>{mensaje}</p>
      </div>

      <div className={styles.acciones}>
        <time className={styles.hora}>{hora}</time>
        <button
          type="button"
          className={styles.botonAtender}
          onClick={onAtender}
        >
          Marcar como atendida
        </button>
      </div>
    </article>
  );
}