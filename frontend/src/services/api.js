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
 *  un 404 de un 409 o un 422 y mostrar el mensaje que corresponda.
 *  `errores` trae el detalle por campo cuando el backend responde 422. */
export class ErrorDeApi extends Error {
  constructor(mensaje, estado, errores = null) {
    super(mensaje);
    this.name = "ErrorDeApi";
    this.estado = estado;
    this.errores = errores;
  }
}

/**
 * Hace una petición y devuelve el JSON (o null si no hay cuerpo, como en
 * un 204 del DELETE).
 *
 * @param {string} ruta    · p. ej. "/api/pacientes"
 * @param {object} opciones
 * @param {string} opciones.method · GET (por defecto), POST, PATCH, DELETE
 * @param {object} opciones.body   · objeto que viaja como JSON
 * @param {object} opciones.params · pares clave/valor para la cadena de consulta
 * @param {AbortSignal} opciones.signal · permite cancelar la petición en curso
 */
export async function pedirJson(
  ruta,
  { method = "GET", body, params = {}, signal } = {},
) {
  const url = new URL(ruta, URL_BASE);

  // URLSearchParams codifica los valores por nosotros. Armar la cadena
  // a mano rompería con un nombre como "Pérez Ñuño" o con un espacio.
  Object.entries(params).forEach(([clave, valor]) => {
    // Un filtro vacío no debe viajar: "?nombre=" no es lo mismo que
    // no enviar el filtro, y el backend podría interpretarlo distinto.
    if (valor === undefined || valor === null || valor === "") return;
    url.searchParams.set(clave, valor);
  });

  const headers = { Accept: "application/json" };
  const opcionesFetch = { method, signal, headers };

  // Solo enviamos cuerpo cuando hay uno; el backend exige JSON explícito.
  if (body !== undefined) {
    headers["Content-Type"] = "application/json";
    opcionesFetch.body = JSON.stringify(body);
  }

  const respuesta = await fetch(url, opcionesFetch);

  // fetch solo rechaza por fallo de red. Un 404 o un 500 llegan como
  // respuesta "exitosa", así que hay que revisar el código a mano.
  if (!respuesta.ok) {
    // El backend manda { message, errors } en 409 y 422: lo leemos para
    // mostrar su mensaje en lugar de un genérico "respondió 409".
    let datos = null;
    try {
      datos = await respuesta.json();
    } catch {
      // Respuesta sin JSON (por ejemplo un 500 con HTML): usamos el genérico.
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

  // 204 No Content (baja lógica): no hay cuerpo que leer.
  if (respuesta.status === 204) return null;


  const texto = await respuesta.text();
  return texto ? JSON.parse(texto) : null;
}