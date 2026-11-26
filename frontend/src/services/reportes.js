import { pedirJson, pedirBlob } from "./api";
export const SESIONES_POR_PAGINA = 10;
const UNIDAD_TIEMPO_A_SEGUNDOS = 1;

function tomar(obj, claves) {
  for (const clave of claves) {
    if (obj?.[clave] !== undefined) return obj[clave];
  }
  return null;
}

function aNumero(valor) {
  if (valor === null || valor === undefined || valor === "") return null;
  const n = Number(valor);
  return Number.isNaN(n) ? null : n;
}

function aSegundos(valor) {
  const n = aNumero(valor);
  return n === null ? null : n * UNIDAD_TIEMPO_A_SEGUNDOS;
}

function construirParams(filtros) {
  const limpio = (v) => (v === "" || v === undefined ? undefined : v);
  const busqueda = filtros.busqueda?.trim() ?? "";

  return {
    fecha_inicio: limpio(filtros.desde),
    fecha_fin: limpio(filtros.hasta),
    paciente_id: /^\d+$/.test(busqueda) ? busqueda : undefined,
    sala: limpio(filtros.sala),
    enfermera_id: limpio(filtros.enfermera),
  };
}


export function adaptarResumen(crudo) {
  const total = aNumero(tomar(crudo, ["total_sesiones", "total"]));

  
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

export function adaptarCategorias(crudo) {
  const contenido = crudo?.data ?? crudo ?? [];

  if (Array.isArray(contenido)) {
    return contenido.map((c) => adaptarCategoria(c));
  }
  return Object.entries(contenido).map(([tipo, c]) =>
    adaptarCategoria(c ?? {}, tipo)
  );
}

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

export function adaptarAlertaAuditoria(crudo) {
  return {
    id: crudo.id,
    tipo: crudo.tipo,
    hora: crudo.hora ?? null,
    descripcion: crudo.descripcion ?? null,
    atendida_por: crudo.atendida_por ?? null,
    resolvio: crudo.resolvio ?? null,
    tiempo_respuesta_seg: aSegundos(crudo.tiempo_respuesta), // null => "—"
  };
}

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


export async function obtenerAuditoriaSesion(id, signal) {
  const respuesta = await pedirJson(`/api/reportes/sesiones/${id}/alertas`, {
    signal,
  });

  const lista = Array.isArray(respuesta) ? respuesta : (respuesta?.data ?? []);
  return lista.map(adaptarAlertaAuditoria);
}

export async function exportarReportePdf(filtros, signal) {
  const blob = await pedirBlob("/api/reportes/exportar", {
    params: construirParams(filtros),
    signal,
  });

  if (!blob.type.includes("pdf")) {
    throw new Error("La respuesta del servidor no es un PDF.");
  }
  return blob;
}