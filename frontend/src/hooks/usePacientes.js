import { useEffect, useState } from "react";
import { obtenerPacientes } from "../services/pacientes";

/**
 * @param {string} nombre
 * @param {string|number} enfermeraId
 */
export function usePacientes({ nombre, enfermeraId }) {
  const [pacientes, setPacientes] = useState([]);
  const [cargando, setCargando] = useState(true);
  const [error, setError] = useState(null);

  useEffect(() => {
    const control = new AbortController();

    setCargando(true);
    setError(null);

    obtenerPacientes({ nombre, enfermeraId, signal: control.signal })
      .then((lista) => {
        setPacientes(lista);
        setError(null);
      })
      .catch((fallo) => {
        if (fallo.name === "AbortError") return;
        setError(fallo);
        setPacientes([]);
      })
      .finally(() => {
        if (!control.signal.aborted) setCargando(false);
      });

    return () => control.abort();
  }, [nombre, enfermeraId]);

  return { pacientes, cargando, error };
}
