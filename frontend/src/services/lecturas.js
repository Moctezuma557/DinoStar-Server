import { pedirJson } from "./api";

export function adaptarLectura(crudo) {
  return {
    gotasPorMin: Number(crudo.gotas_por_min),

    volRestante: Number(crudo.vol_restante),

    tiempoRestanteMin: Number(crudo.tiempo_restante_min),

    marcaTiempo: crudo.timestamp_dispositivo,
  };
}

/**
 * @param {string|number} sesionId
 * @param {AbortSignal} signal
 */
export async function obtenerLecturas({ sesionId, signal } = {}) {
  const respuesta = await pedirJson(`/api/sesiones/${sesionId}/lecturas`, {
    signal,
  });

  const lista = Array.isArray(respuesta) ? respuesta : (respuesta?.data ?? []);

  return lista.map(adaptarLectura);
}
