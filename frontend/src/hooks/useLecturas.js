import { useEffect, useState } from "react";
import { obtenerLecturas } from "../services/lecturas";

/**
 * @param {string|number} sesionId
 * @param {Array} lecturasIniciales
 * @param {number} intervaloMs
 */
export function useLecturas(
  sesionId,
  lecturasIniciales = [],
  intervaloMs = 5000,
) {
  const [lecturas, setLecturas] = useState(lecturasIniciales);
  const [error, setError] = useState(null);

  useEffect(() => {
    if (sesionId == null) {
      setLecturas([]);
      return undefined;
    }

    setLecturas(lecturasIniciales);

    const control = new AbortController();

    async function consultar() {
      try {
        const lista = await obtenerLecturas({
          sesionId,
          signal: control.signal,
        });
        setLecturas(lista);
        setError(null);
      } catch (fallo) {
        if (fallo.name === "AbortError") return;

        setError(fallo);
      }
    }

    const temporizador = setInterval(consultar, intervaloMs);

    return () => {
      clearInterval(temporizador);
      control.abort();
    };
  }, [sesionId, intervaloMs]);

  return { lecturas, error };
}
