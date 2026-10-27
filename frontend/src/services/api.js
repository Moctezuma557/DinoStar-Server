const URL_BASE = import.meta.env.VITE_API_URL ?? "http://localhost:8000";

export class ErrorDeApi extends Error {
  constructor(mensaje, estado) {
    super(mensaje);
    this.name = "ErrorDeApi";
    this.estado = estado;
  }
}

/**
 * Hace una petición GET y devuelve el JSON.
 *
 * @param {string} ruta
 * @param {object} params
 * @param {AbortSignal} signal
 */
export async function pedirJson(ruta, { params = {}, signal } = {}) {
  const url = new URL(ruta, URL_BASE);

  Object.entries(params).forEach(([clave, valor]) => {
    if (valor === undefined || valor === null || valor === "") return;
    url.searchParams.set(clave, valor);
  });

  const respuesta = await fetch(url, {
    signal,
    headers: { Accept: "application/json" },
  });

  if (!respuesta.ok) {
    throw new ErrorDeApi(
      `La petición a ${ruta} respondió ${respuesta.status}`,
      respuesta.status,
    );
  }

  return respuesta.json();
}
