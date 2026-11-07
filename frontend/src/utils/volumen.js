export function numeroONulo(valor) {
  if (valor === null || valor === undefined || valor === "") return null;
  const numero = Number(valor);
  return Number.isFinite(numero) ? numero : null;
}

/**
 * @param {number|string|null} volRestante
 * @param {number|string|null} volTotal
 *
 * @returns {{
 *   hayDato: boolean,      si se puede afirmar algo sobre el volumen
 *   incoherente: boolean,  los números existen pero son imposibles
 *   porcentaje: number,    0–100, listo para el ancho de la barra
 *   restante: number|null,
 *   total: number|null,
 *   texto: string          etiqueta coherente con el porcentaje
 * }}
 */
export function analizarVolumen(volRestante, volTotal) {
  const restante = numeroONulo(volRestante);
  const total = numeroONulo(volTotal);

  if (restante === null || total === null || total <= 0) {
    return {
      hayDato: false,
      incoherente: false,
      porcentaje: 0,
      restante,
      total,
      texto: "Sin lectura",
    };
  }

  const incoherente = restante < 0 || restante > total;

  const crudo = (restante / total) * 100;
  const acotado = Math.min(100, Math.max(0, crudo));
  const porcentaje = Math.floor(acotado);

  return {
    hayDato: true,
    incoherente,
    porcentaje,
    restante,
    total,
    texto: `${porcentaje}% • ${formatearMl(restante)} / ${formatearMl(total)} ml`,
  };
}

function formatearMl(valor) {
  return Number(valor.toFixed(1)).toLocaleString("es-MX");
}
