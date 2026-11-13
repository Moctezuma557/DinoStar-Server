import { pedirJson, ErrorDeApi } from "./api";
import { obtenerPacientes } from "./pacientes";

// Respaldo: id de la sesión creada desde este panel, por paciente.
// Solo se usa si el detalle del paciente no trae su sesión en curso.
const CLAVE_MAPA = "dinostar_sesion_por_paciente";
function leerMapa() {
  try {
    return JSON.parse(localStorage.getItem(CLAVE_MAPA) ?? "{}");
  } catch {
    return {};
  }
}
function guardarEnMapa(pacienteId, sesionId) {
  if (pacienteId === undefined || sesionId === undefined || sesionId === null) return;
  try {
    const mapa = leerMapa();
    mapa[pacienteId] = sesionId;
    localStorage.setItem(CLAVE_MAPA, JSON.stringify(mapa));
  } catch {
    /* sin almacenamiento: se ignora */
  }
}
function quitarDelMapa(pacienteId) {
  try {
    const mapa = leerMapa();
    delete mapa[pacienteId];
    localStorage.setItem(CLAVE_MAPA, JSON.stringify(mapa));
  } catch {
    /* se ignora */
  }
}

// Una sesión está "en curso" si es ACTIVA o PAUSADA (regla de negocio del backend).
const enCurso = (s) =>
  !s?.estado || ["ACTIVA", "PAUSADA"].includes(String(s.estado).toUpperCase());

// Del detalle de un paciente, devuelve su sesión en curso (si la hay).
function elegirSesionEnCurso(crudo) {
  const candidatas = [
    crudo?.sesion,
    crudo?.sesion_activa,
    ...(Array.isArray(crudo?.sesiones) ? crudo.sesiones : []),
  ].filter(Boolean);
  return candidatas.find(enCurso) ?? null;
}

// Convierte una sesión del backend al formato del frontend.
// Acepta tanto campos planos (paciente_nombre) como un objeto `paciente` anidado.
export function adaptarSesionActiva(crudo) {
  return {
    id: crudo.id,
    pacienteId: crudo.paciente_id ?? crudo.paciente?.id,
    pacienteNombre: crudo.paciente_nombre ?? crudo.paciente?.nombre ?? "Paciente",
    cama: crudo.numero_cama ?? crudo.paciente?.numero_cama ?? "",
    enfermeraId: crudo.enfermera_id,
    volTotal: Number(crudo.vol_total),
    modoGoteo: crudo.modo_goteo,
    solucion: crudo.tipo_solucion ?? "Sin solución registrada",
    estado: crudo.estado,
    inicio: crudo.inicio,
  };
}

/**
 * Lista las sesiones en curso.
 *
 * El backend no tiene GET /api/sesiones. La lista de pacientes
 * (GET /api/pacientes) trae a quienes tienen sesión, y la sesión completa
 * (id, estado, inicio) viene en GET /api/pacientes/{id}. Se piden los
 * detalles en paralelo; si alguno falla, esa fila se arma con los datos
 * básicos de la lista.
 */
export async function obtenerSesionesActivas({ signal } = {}) {
  const pacientes = await obtenerPacientes({ signal });

  const detalles = await Promise.allSettled(
    pacientes.map((p) => pedirJson(`/api/pacientes/${p.id}`, { signal })),
  );

  return pacientes.map((p, i) => {
    const resultado = detalles[i];
    const crudo =
      resultado.status === "fulfilled"
        ? (resultado.value?.data ?? resultado.value)
        : null;
    const sesion = elegirSesionEnCurso(crudo);

    return {
      id: sesion?.id ?? null,
      pacienteId: p.id,
      pacienteNombre: p.nombre,
      cama: p.cama,
      enfermeraId: sesion?.enfermera_id ?? p.enfermeraId,
      volTotal: sesion ? Number(sesion.vol_total) : p.volTotal,
      modoGoteo: sesion?.modo_goteo ?? p.modoGoteo,
      solucion: sesion?.tipo_solucion ?? p.solucion,
      estado: sesion?.estado ?? "ACTIVA",
      inicio: sesion?.inicio ?? null,
    };
  });
}

/** POST /api/sesiones  {paciente_id, enfermera_id, vol_total, modo_goteo, tipo_solucion} */
export async function crearSesion(datos) {
  const respuesta = await pedirJson("/api/sesiones", {
    method: "POST",
    body: datos,
  });
  const sesion = adaptarSesionActiva(respuesta?.data ?? respuesta);
  guardarEnMapa(sesion.pacienteId ?? datos.paciente_id, sesion.id);
  return sesion;
}

/** PATCH /api/sesiones/{id}  {vol_total?, modo_goteo?, tipo_solucion?, enfermera_id?} */
export async function actualizarSesion(id, datos) {
  const respuesta = await pedirJson(`/api/sesiones/${id}`, {
    method: "PATCH",
    body: datos,
  });
  return adaptarSesionActiva(respuesta?.data ?? respuesta);
}

/**
 * POST /api/sesiones/{id}/finalizar  → pasa a FINALIZADA y guarda `fin`.
 * 409 si ya estaba finalizada · 404 si no existe.
 */
export async function finalizarSesion(id) {
  try {
    const respuesta = await pedirJson(`/api/sesiones/${id}/finalizar`, {
      method: "POST",
    });
    return adaptarSesionActiva(respuesta?.data ?? respuesta);
  } catch (error) {
    // Según API_SESIONES.md, estas rutas solo aceptan IDs positivos:
    // las sesiones demo (-35001...) responden 404 aunque existan.
    if (error instanceof ErrorDeApi && error.estado === 404 && Number(id) < 0) {
      throw new Error(
        "Esta sesión de demostración (ID negativo) no se puede finalizar desde la API: la ruta solo acepta IDs positivos. Pruebe con una sesión creada desde el panel.",
      );
    }
    throw error;
  }
}

/**
 * Finaliza la sesión en curso de un paciente.
 * Usa el id de sesión de la fila si se conoce; si no, lo busca en el detalle
 * del paciente y, como último recurso, en el id guardado al crearla.
 */
export async function finalizarSesionDePaciente(pacienteId, sesionId = null) {
  let id = sesionId;

  if (id === null || id === undefined) {
    const respuesta = await pedirJson(`/api/pacientes/${pacienteId}`);
    const crudo = respuesta?.data ?? respuesta;
    id = elegirSesionEnCurso(crudo)?.id ?? leerMapa()[pacienteId];
  }

  if (id === null || id === undefined) {
    throw new Error(
      "No se pudo identificar la sesión en curso de este paciente.",
    );
  }

  const resultado = await finalizarSesion(id);
  quitarDelMapa(pacienteId);
  return resultado;
}