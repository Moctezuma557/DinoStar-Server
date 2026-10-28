import { pedirJson } from "./api";

function calcularIniciales(nombre) {
  return nombre
    .split(" ")
    .filter((parte) => parte.length > 1 && !parte.includes("."))
    .slice(-2)
    .map((parte) => parte[0])
    .join("")
    .toUpperCase();
}
function formatearTurno(turno) {
  if (!turno) return "Sin turno";
  return turno.charAt(0) + turno.slice(1).toLowerCase();
}

function adaptarEnfermera(crudo) {
  return {
    id: crudo.id,
    nombre: crudo.nombre,
    turno: formatearTurno(crudo.turno),
    iniciales: calcularIniciales(crudo.nombre),
  };
}

/**
 * @param {string} nombre
 * @param {AbortSignal} signal
 */
export async function obtenerEnfermeras({ nombre, signal } = {}) {
  const respuesta = await pedirJson("/api/enfermeras", {
    params: { nombre },
    signal,
  });

  const lista = Array.isArray(respuesta) ? respuesta : (respuesta?.data ?? []);
  return lista.map(adaptarEnfermera);
}

/**
 * @param {string|number} id
 * @param {AbortSignal} signal
 */
export async function obtenerEnfermera({ id, signal } = {}) {
  const respuesta = await pedirJson(`/api/enfermeras/${id}`, { signal });
  const crudo = respuesta?.data ?? respuesta;

  if (!crudo) return null;
  return adaptarEnfermera(crudo);
}
