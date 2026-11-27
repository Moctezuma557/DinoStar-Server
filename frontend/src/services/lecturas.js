import { pedirJson } from "./api";

export function adaptarLectura(crudo) {
  return {
    gotasPorMin: Number(crudo.gotas_por_min),
    volRestante: Number(crudo.vol_restante),
    tiempoRestanteMin: Number(crudo.tiempo_restante_min),
    marcaTiempo: crudo.timestamp_dispositivo,
  };
}

async function pedirPagina({ sesionId, cantidad, pagina, signal }) {
  const respuesta = await pedirJson(`/api/sesiones/${sesionId}/lecturas`, {
    params: { limit: cantidad, page: pagina },
    signal,
  });

  const lista = Array.isArray(respuesta?.data) ? respuesta.data : [];
  const total = Number(respuesta?.meta?.total);
  const paginaActual = Number(respuesta?.meta?.current_page);

  return {
    lecturas: lista.map(adaptarLectura),
    total: Number.isFinite(total) ? total : lista.length,
    paginaActual: Number.isFinite(paginaActual) ? paginaActual : 1,
  };
}

export async function obtenerVentana({
  sesionId,
  cantidad,
  pagina = 1,
  signal,
}) {
  const masReciente = await pedirPagina({ sesionId, cantidad, signal });
  const { total } = masReciente;

  const fin = total - (pagina - 1) * cantidad;
  if (fin <= 0) return { lecturas: [], total };
  const inicio = Math.max(0, fin - cantidad);

  const paginaInicial = Math.floor(inicio / cantidad) + 1;
  const paginaFinal = Math.floor((fin - 1) / cantidad) + 1;

  const paginas = new Map([[masReciente.paginaActual, masReciente.lecturas]]);
  const faltantes = [...new Set([paginaInicial, paginaFinal])].filter(
    (numero) => !paginas.has(numero),
  );

  const recibidas = await Promise.all(
    faltantes.map((numero) =>
      pedirPagina({ sesionId, cantidad, pagina: numero, signal }),
    ),
  );
  recibidas.forEach((recibida, indice) =>
    paginas.set(faltantes[indice], recibida.lecturas),
  );

  const combinadas =
    paginaInicial === paginaFinal
      ? paginas.get(paginaInicial)
      : [...paginas.get(paginaInicial), ...paginas.get(paginaFinal)];

  const desplazamiento = (paginaInicial - 1) * cantidad;

  return {
    lecturas: combinadas.slice(inicio - desplazamiento, fin - desplazamiento),
    total,
  };
}

export async function obtenerUltimaLectura({ sesionId, signal }) {
  const { lecturas } = await pedirPagina({ sesionId, cantidad: 1, signal });
  return lecturas[0] ?? null;
}
