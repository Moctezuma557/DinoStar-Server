import { useRef, useEffect } from "react";
import styles from "./AlertaBanner.module.css";

export default function AlertaBanner({ alertas = [], onClose }) {
  const bannerRef = useRef(null);

  useEffect(() => {
    function handleClickOutside(event) {
      if (bannerRef.current && !bannerRef.current.contains(event.target)) {
        onClose();
      }
    }
    document.addEventListener("mousedown", handleClickOutside);
    return () => {
      document.removeEventListener("mousedown", handleClickOutside);
    };
  }, [onClose]);

  const claseGrid = alertas.length === 4 ? styles.cuerpoGrid4 : styles.cuerpoGrid1;

  return (
    <div className={styles.container} ref={bannerRef}>
      {/* Cabecera del Banner */}
      <div className={styles.header}>
        <div className={styles.tituloWrapper}>
          <h3 className={styles.titulo}>¡ALERTA CRÍTICA DE INFUSIÓN!</h3>
        </div>
        <span className={styles.badgeAlerta}>ALERTA</span>
      </div>

      {/* Contenedor dinámico de alertas dentro de la misma tarjeta */}
      <div className={claseGrid}>
        {alertas.length === 0 ? (
          <p className={styles.sinAlertas}>No hay alertas activas en este momento.</p>
        ) : (
          alertas.map((alerta, index) => (
            <div key={alerta.id || index} className={styles.tarjetaAlerta}>
              <div className={styles.filaSuperior}>
                <span className={styles.nombrePaciente}>
                  {alerta.nombre} — Hab. {alerta.cama}
                </span>
                <span className={styles.tiempo}>
                  {alerta.horaAlerta || "Reciente"}
                </span>
              </div>
              <p className={styles.mensaje}>
                {alerta.textoEstado || "ALERTA DE INFUSIÓN"} 
              </p>
              <div className={styles.detallesMetricas}>
                <span>
                  Goteo: <strong className={styles.valorGoteo}>{alerta.gotasPorMin ?? 0} gtt/min</strong>
                </span>
                <span className={styles.textoEsperado}>Esperado: {alerta.esperado || "120 ml/h"}</span>
              </div>
            </div>
          ))
        )}
      </div>
    </div>
  );
}