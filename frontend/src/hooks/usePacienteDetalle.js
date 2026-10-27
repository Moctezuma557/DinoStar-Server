import { useEffect, useState } from "react";
import { obtenerPacientePorId } from "../services/pacientes";
import { obtenerEnfermera } from "../services/enfermeras";

/**
 * @param {string|number} id
 */
export function usePacienteDetalle(id) {
  const [detalle, setDetalle] = useState(null);
  const [enfermera, setEnfermera] = useState(null);
  const [cargando, setCargando] = useState(true);
  const [error, setError] = useState(null);

  useEffect(() => {
    const control = new AbortController();

    setCargando(true);
    setError(null);

    async function cargar() {
      try {
        const datos = await obtenerPacientePorId({
          id,
          signal: control.signal,
        });

        setDetalle(datos);

        const enfermeraId = datos?.sesion?.enfermeraId;
        if (enfermeraId != null) {
          try {
            const encontrada = await obtenerEnfermera({
              id: enfermeraId,
              signal: control.signal,
            });
            setEnfermera(encontrada);
          } catch (falloEnfermera) {
            if (falloEnfermera.name !== "AbortError") setEnfermera(null);
          }
        } else {
          setEnfermera(null);
        }
      } catch (fallo) {
        if (fallo.name === "AbortError") return;

        setError(fallo);
        setDetalle(null);
        setEnfermera(null);
      } finally {
        if (!control.signal.aborted) setCargando(false);
      }
    }

    cargar();

    return () => control.abort();
  }, [id]);

  return { detalle, enfermera, cargando, error };
}
