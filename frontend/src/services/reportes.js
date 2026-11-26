
import { pedirJson } from "./api";

export const SESIONES_POR_PAGINA = 10;

// B1 devuelve los tiempos en segundos (EXTRACT(EPOCH ...)), por eso es 1.
const UNIDAD_TIEMPO_A_SEGUNDOS = 1;
// Devuelve el primer campo que exista.
function tomar(obj, claves) {
  for (const clave of claves) {
    if (obj?.[clave] !== undefined) return obj[clave];
  }
  return null;
}

// null se queda en null (la UI lo muestra como "—"); nunca se convierte en 0.
function aNumero(valor) {
  if (valor === null || valor === undefined || valor === "") return null;
  const n = Number(valor);
  return Number.isNaN(n) ? null : n;
}

function aSegundos(valor) {
  const n = aNumero(valor);
  return n === null ? null : n * UNIDAD_TIEMPO_A_SEGUNDOS;
}

// Filtros de la UI -> parámetros del contrato. Vacío => no se envía.
function construirParams(filtros) {
  const limpio = (v) => (v === "" || v === undefined ? undefined : v);
  const busqueda = filtros.busqueda?.trim() ?? "";

  return {
    fecha_inicio: limpio(filtros.desde),
    fecha_fin: limpio(filtros.hasta),
    // B1 valida paciente_id como entero (id de la tabla pacientes): un texto
    // como "Elena" daría error 422, así que solo se envía si es numérico.
    paciente_id: /^\d+$/.test(busqueda) ? busqueda : undefined,
    sala: limpio(filtros.sala),
    enfermera_id: limpio(filtros.enfermera),
  };
}

/* ------------------------------------------------------------------ */
/* Adaptadores: respuesta del backend -> forma que usa la UI           */
/* ------------------------------------------------------------------ */

export function adaptarResumen(crudo) {
  const total = aNumero(tomar(crudo, ["total_sesiones", "total"]));

  // B1 devuelve sesiones_completadas como { cantidad, porcentaje }.
  const bloque = tomar(crudo, ["sesiones_completadas", "completadas"]);
  const esObjeto = bloque !== null && typeof bloque === "object";
  const completadas = aNumero(esObjeto ? bloque.cantidad : bloque);
  const porcentaje = aNumero(
    esObjeto
      ? bloque.porcentaje
      : tomar(crudo, ["porcentaje_completadas", "completadas_porcentaje"])
  );

  return {
    total_sesiones: total,
    sesiones_completadas: completadas,
    porcentaje_completadas:
      porcentaje ??
      (total && completadas !== null ? (completadas / total) * 100 : null),
    sesiones_con_alertas: aNumero(
      tomar(crudo, ["sesiones_con_alertas", "con_alertas"])
    ),
    tiempo_promedio_respuesta_seg: aSegundos(
      tomar(crudo, ["tiempo_promedio_respuesta", "tiempo_promedio_respuesta_seg"])
    ),
  };
}

function adaptarCategoria(crudo, tipoPorDefecto) {
  return {
    tipo: crudo.tipo ?? tipoPorDefecto,
    cantidad: aNumero(crudo.cantidad),
    porcentaje: aNumero(crudo.porcentaje),
    promedio_resolucion_seg: aSegundos(
      tomar(crudo, ["tiempo_promedio_resolucion", "promedio_resolucion_seg"])
    ),
  };
}

// K1 responde { data: [ {tipo, cantidad, porcentaje, tiempo_promedio_resolucion} ] }
export function adaptarCategorias(crudo) {
  const contenido = crudo?.data ?? crudo ?? [];

  if (Array.isArray(contenido)) {
    return contenido.map((c) => adaptarCategoria(c));
  }
  return Object.entries(contenido).map(([tipo, c]) =>
    adaptarCategoria(c ?? {}, tipo)
  );
}

// SesionReporteResource -> forma que usa la tabla.
export function adaptarSesion(crudo) {
  const ubicacion = crudo.ubicacion ?? {};
  return {
    id: crudo.id,
    paciente: crudo.paciente ?? "Sin nombre",
    enfermera: crudo.enfermera ?? null,
    sala: ubicacion.sala ?? null,
    cama: ubicacion.numero_cama ?? null,
    inicio: crudo.inicio ?? null,
    fin: crudo.fin ?? null, // null = sesión en curso
    duracion_segundos: aSegundos(crudo.duracion_segundos),
    solucion: crudo.tipo_solucion ?? null,
    volumen: aNumero(crudo.vol_total),
    numero_alertas: aNumero(crudo.numero_alertas) ?? 0,
    estado: crudo.estado ?? null,
  };
}

// AuditoriaAlertaResource -> forma que usa el panel de auditoría.
export function adaptarAlertaAuditoria(crudo) {
  return {
    id: crudo.id,
    tipo: crudo.tipo,
    hora: crudo.hora ?? null,
    descripcion: crudo.descripcion ?? null,
    atendida_por: crudo.atendida_por ?? null, // null = alerta sin atender
    resolvio: crudo.resolvio ?? null,
    tiempo_respuesta_seg: aSegundos(crudo.tiempo_respuesta), // null => "—"
  };
}

// Respuesta paginada de Laravel: { data: [...], meta: {...}, links: {...} }
function adaptarPagina(respuesta, paginaPedida) {
  const lista = Array.isArray(respuesta) ? respuesta : (respuesta?.data ?? []);
  const meta = respuesta?.meta ?? {};

  return {
    filas: lista.map(adaptarSesion),
    pagina: aNumero(meta.current_page) ?? paginaPedida,
    totalPaginas: aNumero(meta.last_page) ?? 1,
    total: aNumero(meta.total) ?? lista.length,
    desde: aNumero(meta.from),
    hasta: aNumero(meta.to),
  };
}

/* ------------------------------------------------------------------ */
/* Funciones que usa la página                                         */
/* ------------------------------------------------------------------ */

/**
 * KPIs + desglose por categoría (K1).
 * @param {object} filtros  { desde, hasta, busqueda, sala, enfermera }
 * @param {AbortSignal} signal
 */
export async function obtenerReporte(filtros, signal) {
  const params = construirParams(filtros);

  const [resumen, categorias] = await Promise.all([
    pedirJson("/api/reportes/resumen", { params, signal }),
    pedirJson("/api/reportes/alertas-categoria", { params, signal }),
  ]);

  return {
    resumen: adaptarResumen(resumen?.data ?? resumen),
    categorias: adaptarCategorias(categorias),
  };
}

/**
 * Sesiones históricas paginadas (K2).
 * @param {object} filtros
 * @param {{pagina?: number, porPagina?: number}} paginacion
 * @param {AbortSignal} signal
 */
export async function obtenerSesiones(
  filtros,
  { pagina = 1, porPagina = SESIONES_POR_PAGINA } = {},
  signal
) {
  const respuesta = await pedirJson("/api/reportes/sesiones", {
    params: { ...construirParams(filtros), page: pagina, per_page: porPagina },
    signal,
  });

  return adaptarPagina(respuesta, pagina);
}

/**
 * Auditoría de alertas de una sesión (K2).
 * @param {number|string} id  id de la sesión
 * @param {AbortSignal} signal
 */
export async function obtenerAuditoriaSesion(id, signal) {
  const respuesta = await pedirJson(`/api/reportes/sesiones/${id}/alertas`, {
    signal,
  });

  const lista = Array.isArray(respuesta) ? respuesta : (respuesta?.data ?? []);
  return lista.map(adaptarAlertaAuditoria);
}