/**
 * DinoStar REX · Acceso a la API
 *
 * Único punto por el que el frontend sale a la red. Centralizarlo aquí
 * evita repetir la URL base y el manejo de errores en cada vista, y
 * deja un solo lugar que cambiar el día que el backend viva en otra
 * dirección.
 */

/**
 * La dirección se toma de una variable de entorno con valor de
 * respaldo, para que el proyecto levante sin configuración previa
 * pero se pueda apuntar a otro servidor sin tocar el código.
 */
const URL_BASE = import.meta.env.VITE_API_URL ?? "http://localhost:8000";

/** Error con el código HTTP, para que quien lo atrape pueda distinguir
 *  un 404 de un 500 y mostrar el mensaje que corresponda. */
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
 * @param {string} ruta    · p. ej. "/api/pacientes"
 * @param {object} params  · pares clave/valor para la cadena de consulta
 * @param {AbortSignal} signal · permite cancelar la petición en curso
 */
export async function pedirJson(ruta, { params = {}, signal } = {}) {
  const url = new URL(ruta, URL_BASE);

  // URLSearchParams codifica los valores por nosotros. Armar la cadena
  // a mano rompería con un nombre como "Pérez Ñuño" o con un espacio.
  Object.entries(params).forEach(([clave, valor]) => {
    // Un filtro vacío no debe viajar: "?nombre=" no es lo mismo que
    // no enviar el filtro, y el backend podría interpretarlo distinto.
    if (valor === undefined || valor === null || valor === "") return;
    url.searchParams.set(clave, valor);
  });

  const respuesta = await fetch(url, {
    signal,
    headers: { Accept: "application/json" },
  });

  // fetch solo rechaza por fallo de red. Un 404 o un 500 llegan como
  // respuesta "exitosa", así que hay que revisar el código a mano.
  if (!respuesta.ok) {
    throw new ErrorDeApi(
      `La petición a ${ruta} respondió ${respuesta.status}`,
      respuesta.status,
    );
  }

  return respuesta.json();
}
