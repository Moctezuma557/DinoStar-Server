const URL_BASE = import.meta.env.VITE_API_URL ?? "http://localhost:8000";
export class ErrorDeApi extends Error {
  constructor(mensaje, estado, errores = null) {
    super(mensaje);
    this.name = "ErrorDeApi";
    this.estado = estado;
    this.errores = errores;
  }
}
export async function pedirJson(
  ruta,
  { method = "GET", body, params = {}, signal } = {},
) {
  const url = new URL(ruta, URL_BASE);

  Object.entries(params).forEach(([clave, valor]) => {
    if (valor === undefined || valor === null || valor === "") return;
    url.searchParams.set(clave, valor);
  });

  const headers = { Accept: "application/json" };
  const opcionesFetch = { method, signal, headers };
  if (body !== undefined) {
    headers["Content-Type"] = "application/json";
    opcionesFetch.body = JSON.stringify(body);
  }

  const respuesta = await fetch(url, opcionesFetch);

  if (!respuesta.ok) {
    let datos = null;
    try {
      datos = await respuesta.json();
    } catch {
    }

    let mensaje =
      datos?.message ??
      `La petición a ${ruta} respondió ${respuesta.status}`;

    // En 422, añadimos el detalle de cada campo para que se vea qué falló.
    if (datos?.errors && typeof datos.errors === "object") {
      const detalle = Object.values(datos.errors).flat().join(" ");
      if (detalle) mensaje = `${mensaje} ${detalle}`;
    }

    throw new ErrorDeApi(mensaje, respuesta.status, datos?.errors ?? null);
  }

  if (respuesta.status === 204) return null;


  const texto = await respuesta.text();
  return texto ? JSON.parse(texto) : null;
}

export async function pedirBlob(ruta, { params = {}, signal } = {}) {
  const url = new URL(ruta, URL_BASE);

  Object.entries(params).forEach(([clave, valor]) => {
    if (valor === undefined || valor === null || valor === "") return;
    url.searchParams.set(clave, valor);
  });

  const respuesta = await fetch(url, {
    signal,
    headers: { Accept: "application/json, application/pdf" },
  });

    if (!respuesta.ok) {
    const datos = await respuesta.json().catch(() => null);

    let mensaje =
      datos?.message ?? `La petición a ${ruta} respondió ${respuesta.status}`;

    if (datos?.errors && typeof datos.errors === "object") {
      const detalle = Object.values(datos.errors).flat().join(" ");
      if (detalle) mensaje = `${mensaje} ${detalle}`;
    }

    throw new ErrorDeApi(mensaje, respuesta.status, datos?.errors ?? null);
  }

  return respuesta.blob();
}