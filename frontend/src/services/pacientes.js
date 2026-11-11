import { pedirJson } from "./api";
import { adaptarLectura } from "./lecturas";

// Convierte el paciente que manda el backend (snake_case)
// al formato que usa el frontend (camelCase).
export function adaptarPaciente(crudo) {
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

export async function obtenerPacientePorId({ id, signal } = {}) {
  const respuesta = await pedirJson(`/api/pacientes/${id}`, { signal });

  const crudo = respuesta?.data ?? respuesta;

  return adaptarDetalle(crudo);
}

export async function crearPaciente(datosPaciente) {
  const respuesta = await pedirJson("/api/pacientes", {
    method: "POST",
    body: datosPaciente,
  });
  console.log("[crearPaciente] respuesta del backend:", respuesta);

  const crudo = respuesta?.data ?? respuesta;
  if (!crudo || Array.isArray(crudo) || crudo.id === undefined) {
    throw new Error(
      "El servidor no devolvió el paciente creado (llegó una lista). Revisa que pedirJson envíe el método POST y el body."
    );
  }
  // El POST abre una sesión: los datos de la bolsa pueden venir dentro de `sesion`
  const s = crudo.sesion ?? {};
  return adaptarPaciente({
    ...crudo,
    vol_total: crudo.vol_total ?? s.vol_total,
    modo_goteo: crudo.modo_goteo ?? s.modo_goteo,
    tipo_solucion: crudo.tipo_solucion ?? s.tipo_solucion,
    enfermera_id: crudo.enfermera_id ?? s.enfermera_id,
  });
}

// El PATCH del backend SOLO acepta nombre, numero_cama y sala.
// Los demás campos se ignoran y la respuesta solo trae esos mismos campos.
export async function actualizarPaciente(id, datosActualizados) {
  const { nombre, numero_cama, sala } = datosActualizados;
  const respuesta = await pedirJson(`/api/pacientes/${id}`, {
    method: "PATCH",
    body: { nombre, numero_cama, sala },
  });
  console.log("[actualizarPaciente] respuesta del backend:", respuesta);

  const crudo = respuesta?.data ?? respuesta;
  if (!crudo || Array.isArray(crudo) || crudo.numero_cama === undefined) {
    throw new Error(
      "El servidor no confirmó la edición. Revisa que pedirJson envíe el método PATCH y el body."
    );
  }
  // Devolvemos solo lo que cambió, para no pisar volTotal, solucion, etc.
  return {
    id: crudo.id,
    nombre: crudo.nombre,
    cama: crudo.numero_cama,
    sala: crudo.sala,
  };
}

export async function darDeBajaPaciente(id, finalizarSesion = true) {
  await pedirJson(`/api/pacientes/${id}`, {
    method: "DELETE",
    body: { finalizar_sesion: finalizarSesion },
  });
  return true;
}