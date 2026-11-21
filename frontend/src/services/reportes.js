// frontend/src/services/reportes.js
//
// Contrato (Documentación corte 5, sección 5):
//   GET /api/reportes/resumen
//       ?fecha_inicio=&fecha_fin=&paciente_id=&sala=&enfermera_id=
//   GET /api/reportes/alertas-categoria   ?<mismos filtros>
//
// Mientras K1 no esté mergeado (lun 24 nov) se usa USAR_DATOS_LOCALES = true.
// El día que K1 esté listo: cambiar a false. No hay que tocar reportes.jsx.

import { pedirJson } from "./api";

const USAR_DATOS_LOCALES = true;

// Si el backend devuelve el tiempo promedio en otra unidad, cambiar SOLO esto:
// B1 devuelve segundos (EXTRACT(EPOCH ...)), así que el valor correcto es 1.
// 1 = ya viene en segundos, 60 = viene en minutos, 0.001 = viene en milisegundos.
const UNIDAD_TIEMPO_A_SEGUNDOS = 1;

/* ------------------------------------------------------------------ */
/* Utilidades                                                          */
/* ------------------------------------------------------------------ */

// Devuelve el primer campo que exista (el contrato no fija todos los nombres).
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
  return {
    fecha_inicio: limpio(filtros.desde),
    fecha_fin: limpio(filtros.hasta),
    // B1 valida paciente_id como entero (id de la tabla pacientes): un texto
    // como "Elena" daría error 422, así que solo se envía si es numérico.
    paciente_id: /^\d+$/.test(filtros.busqueda?.trim() ?? "")
      ? filtros.busqueda.trim()
      : undefined,
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
  // También se acepta un número suelto + un campo de porcentaje aparte.
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
      tomar(crudo, [
        "tiempo_promedio_respuesta",
        "tiempo_promedio_respuesta_seg",
        "tiempo_prom_respuesta",
      ])
    ),
  };
}

function adaptarCategoria(crudo, tipoPorDefecto) {
  return {
    tipo: crudo.tipo ?? tipoPorDefecto,
    cantidad: aNumero(tomar(crudo, ["cantidad", "total", "count"])),
    porcentaje: aNumero(tomar(crudo, ["porcentaje", "pct", "%"])),
    promedio_resolucion_seg: aSegundos(
      tomar(crudo, [
        "tiempo_promedio_resolucion",
        "promedio_resolucion_seg",
        "promedio_resolucion",
        "prom_resolucion",
      ])
    ),
  };
}

// Acepta una lista [{tipo, ...}] o un objeto { GOTEO_LENTO: {...}, ... }
export function adaptarCategorias(crudo) {
  const contenido = crudo?.categorias ?? crudo?.data ?? crudo ?? [];

  if (Array.isArray(contenido)) {
    return contenido.map((c) => adaptarCategoria(c));
  }
  return Object.entries(contenido).map(([tipo, c]) =>
    adaptarCategoria(c ?? {}, tipo)
  );
}

/* ------------------------------------------------------------------ */
/* Datos de ejemplo (con la forma del contrato). Se borran con K1.     */
/* Escribe "zzz" en el buscador para ver el estado vacío.              */
/* ------------------------------------------------------------------ */

function respuestasLocales(filtros) {
  const vacio = filtros.busqueda?.trim().toLowerCase() === "zzz";

  return new Promise((resolve) => {
    setTimeout(() => {
      if (vacio) {
        resolve({
          resumen: {
            total_sesiones: 0,
            sesiones_completadas: 0,
            porcentaje_completadas: null,
            sesiones_con_alertas: 0,
            tiempo_promedio_respuesta_seg: null,
          },
          categorias: [],
        });
        return;
      }
      resolve({
        resumen: {
          total_sesiones: 1420,
          sesiones_completadas: 1308,
          porcentaje_completadas: 92.1,
          sesiones_con_alertas: 86,
          tiempo_promedio_respuesta_seg: 144,
        },
        categorias: [
          { tipo: "GOTEO_LENTO", cantidad: 48, porcentaje: 55.8, promedio_resolucion_seg: 105 },
          { tipo: "FIN_BOLSA", cantidad: 24, porcentaje: 27.9, promedio_resolucion_seg: 130 },
          { tipo: "GOTEO_RAPIDO", cantidad: 14, porcentaje: 16.3, promedio_resolucion_seg: null },
        ],
      });
    }, 600);
  });
}

/* ------------------------------------------------------------------ */
/* Función que usa la página                                           */
/* ------------------------------------------------------------------ */

/**
 * @param {object} filtros  { desde, hasta, busqueda, sala, enfermera }
 * @param {AbortSignal} signal
 * @returns {Promise<{resumen: object, categorias: object[]}>}
 */
export async function obtenerReporte(filtros, signal) {
  if (USAR_DATOS_LOCALES) {
    const local = await respuestasLocales(filtros);
    return {
      resumen: adaptarResumen(local.resumen),
      categorias: adaptarCategorias(local.categorias),
    };
  }

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