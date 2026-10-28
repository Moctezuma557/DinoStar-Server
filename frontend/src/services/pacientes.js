import { pedirJson } from "./api";
import { adaptarLectura } from "./lecturas";

function adaptarPaciente(crudo) {
  return {
    id: crudo.id,
    nombre: crudo.nombre,
    cama: crudo.numero_cama,
    sala: crudo.sala,

    solucion: crudo.tipo_solucion ?? "Sin solución registrada",

    modoGoteo: crudo.modo_goteo,

    volTotal: Number(crudo.vol_total),

    enfermeraId: crudo.enfermera_id,

    tieneDispositivo: Boolean(crudo.modo_goteo),
  };
}

function adaptarSesion(crudo) {
  if (!crudo) return null;

  return {
    id: crudo.id,
    enfermeraId: crudo.enfermera_id,
    volTotal: Number(crudo.vol_total),
    solucion: crudo.tipo_solucion ?? "Sin solución registrada",
    modoGoteo: crudo.modo_goteo,
    estado: crudo.estado,
    inicio: crudo.inicio,
    fin: crudo.fin,
  };
}

function adaptarDetalle(crudo) {
  const sesion = crudo.sesion ?? null;

  return {
    id: crudo.id,
    nombre: crudo.nombre,
    cama: crudo.numero_cama,
    sala: crudo.sala,
    activo: crudo.activo,
    sesion: adaptarSesion(sesion),
    lecturas: (sesion?.lecturas ?? []).map(adaptarLectura),
  };
}

/**
 * @param {string} nombre
 * @param {string|number} enfermeraId
 * @param {AbortSignal} signal
 */
export async function obtenerPacientes({ nombre, enfermeraId, signal } = {}) {
  const respuesta = await pedirJson("/api/pacientes", {
    params: { nombre, enfermera_id: enfermeraId },
    signal,
  });

  const lista = Array.isArray(respuesta) ? respuesta : (respuesta?.data ?? []);

  return lista.map(adaptarPaciente);
}

/**
 * @param {string|number} id
 * @param {AbortSignal} signal
 */
export async function obtenerPacientePorId({ id, signal } = {}) {
  const respuesta = await pedirJson(`/api/pacientes/${id}`, { signal });

  const crudo = respuesta?.data ?? respuesta;

  return adaptarDetalle(crudo);
}
