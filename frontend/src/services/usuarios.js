import { pedirJson } from "./api";

// Convierte el usuario del backend al formato del frontend.
export function adaptarUsuario(crudo) {
  return {
    id: crudo.id,
    nombre: crudo.nombre,
    email: crudo.email,
    rol: crudo.rol,
    turno: crudo.turno ?? null,
    activo: Boolean(crudo.activo),
  };
}

/**
 * GET /api/usuarios?rol=&activo=&nombre=
 * Todos los filtros son opcionales. `activo` acepta true/false.
 */
export async function obtenerUsuarios({ rol, activo, nombre, signal } = {}) {
  const respuesta = await pedirJson("/api/usuarios", {
    params: { rol, activo, nombre },
    signal,
  });
  const lista = Array.isArray(respuesta) ? respuesta : (respuesta?.data ?? []);
  return lista.map(adaptarUsuario);
}

/** POST /api/usuarios  {nombre, email, password, rol, turno, activo} */
export async function crearUsuario(datos) {
  const respuesta = await pedirJson("/api/usuarios", {
    method: "POST",
    body: datos,
  });
  return adaptarUsuario(respuesta?.data ?? respuesta);
}

/** PATCH /api/usuarios/{id}  (cualquier subconjunto de campos, al menos uno) */
export async function actualizarUsuario(id, datos) {
  const respuesta = await pedirJson(`/api/usuarios/${id}`, {
    method: "PATCH",
    body: datos,
  });
  return adaptarUsuario(respuesta?.data ?? respuesta);
}

/** DELETE /api/usuarios/{id}  → baja lógica (204 sin cuerpo) */
export async function darDeBajaUsuario(id) {
  await pedirJson(`/api/usuarios/${id}`, { method: "DELETE" });
  return true;
}

/** Reactiva a un usuario dado de baja */
export function reactivarUsuario(id) {
  return actualizarUsuario(id, { activo: true });
}