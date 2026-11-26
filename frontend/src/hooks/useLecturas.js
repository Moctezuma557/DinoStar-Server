import { useEffect, useState } from "react";
import { obtenerUltimaLectura, obtenerVentana } from "../services/lecturas";

export function useLecturas(
  sesionId,
  { cantidad = 25, pagina = 1, intervaloMs = 5000 } = {},
) {
  const clave = `${sesionId}|${cantidad}|${pagina}`;

  const [ventana, setVentana] = useState({
    clave: null,
    sesionId: null,
    lecturas: [],
    total: 0,
  });
  const [ultima, setUltima] = useState({ sesionId: null, lectura: null });
  const [error, setError] = useState(null);

  useEffect(() => {
    if (sesionId == null) return undefined;

    const control = new AbortController();
    const enVivo = pagina === 1;
    let enCurso = false;

    async function ciclo(primeraVez) {
      if (enCurso) return;
      enCurso = true;

      try {
        if (enVivo || primeraVez) {
          const resultado = await obtenerVentana({
            sesionId,
            cantidad,
            pagina,
            signal: control.signal,
          });
          setVentana({
            clave,
            sesionId,
            lecturas: resultado.lecturas,
            total: resultado.total,
          });

          if (enVivo) {
            const lista = resultado.lecturas;
            setUltima({
              sesionId,
              lectura: lista.length > 0 ? lista[lista.length - 1] : null,
            });
          }
        }

        if (!enVivo) {
          const lectura = await obtenerUltimaLectura({
            sesionId,
            signal: control.signal,
          });
          setUltima({ sesionId, lectura });
        }

        setError(null);
      } catch (fallo) {
        if (fallo?.name === "AbortError") return;
        setError({ sesionId, fallo });
      } finally {
        enCurso = false;
      }
    }
    ciclo(true);

    const temporizador = setInterval(() => ciclo(false), intervaloMs);

    return () => {
      clearInterval(temporizador);
      control.abort();
    };
  }, [sesionId, cantidad, pagina, intervaloMs, clave]);

  const mismaSesion = ventana.sesionId === sesionId;
  const errorActual = error && error.sesionId === sesionId ? error.fallo : null;

  return {
    lecturas: mismaSesion ? ventana.lecturas : [],
    total: mismaSesion ? ventana.total : 0,
    ultima: ultima.sesionId === sesionId ? ultima.lectura : null,
    cargando: sesionId != null && !mismaSesion && errorActual === null,
    actualizando: mismaSesion && ventana.clave !== clave,
    error: errorActual,
  };
}
