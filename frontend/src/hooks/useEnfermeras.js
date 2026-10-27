import { useEffect, useState } from "react";
import { obtenerEnfermeras } from "../services/enfermeras";

export function useEnfermeras() {
  const [enfermeras, setEnfermeras] = useState([]);
  const [cargando, setCargando] = useState(true);
  const [error, setError] = useState(null);

  useEffect(() => {
    const control = new AbortController();

    obtenerEnfermeras({ signal: control.signal })
      .then((lista) => {
        setEnfermeras(lista);
        setError(null);
      })
      .catch((fallo) => {
        if (fallo.name === "AbortError") return;
        setError(fallo);
        setEnfermeras([]);
      })
      .finally(() => {
        if (!control.signal.aborted) setCargando(false);
      });

    return () => control.abort();
  }, []);

  return { enfermeras, cargando, error };
}
