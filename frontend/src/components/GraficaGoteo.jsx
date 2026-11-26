import { useId, useMemo } from "react";
import {
  CartesianGrid,
  Line,
  LineChart,
  ResponsiveContainer,
  Tooltip,
  XAxis,
  YAxis,
} from "recharts";

const COLOR_LINEA = "#5a9bd5";
const COLOR_REJILLA = "#e3e9f2";
const COLOR_EJE = "#cbd5e1";
const COLOR_TEXTO_EJE = "#64748b";
const MAXIMO_PUNTOS_VISIBLES = 30;

function formatearHora(milisegundos) {
  return new Date(milisegundos).toLocaleTimeString("es-MX", {
    hour: "2-digit",
    minute: "2-digit",
    second: "2-digit",
    hour12: false,
  });
}

function formatearGotas(valor) {
  return `${valor.toLocaleString("es-MX", { maximumFractionDigits: 1 })} gtt/min`;
}


function calcularMarcasY(maximo) {
  const pasoMinimo = Math.max(maximo * 1.1, 1) / 4;
  const magnitud = 10 ** Math.floor(Math.log10(pasoMinimo));
  const paso = [1, 2, 5, 10]
    .map((factor) => factor * magnitud)
    .find((candidato) => candidato >= pasoMinimo);
  return [0, paso, paso * 2, paso * 3, paso * 4];
}


function prepararPuntos(lecturas) {
  const puntos = lecturas
    .map((lectura) => ({
      t: new Date(lectura.marcaTiempo).getTime(),
      gotas: lectura.gotasPorMin,
    }))
    .filter((punto) => Number.isFinite(punto.t) && Number.isFinite(punto.gotas))
    .sort((a, b) => a.t - b.t);

  return { puntos, descartadas: lecturas.length - puntos.length };
}

function resumir(puntos) {
  const valores = puntos.map((punto) => punto.gotas);
  return {
    minimo: Math.min(...valores),
    maximo: Math.max(...valores),
    ultimo: valores[valores.length - 1],
    desde: formatearHora(puntos[0].t),
    hasta: formatearHora(puntos[puntos.length - 1].t),
  };
}

export default function GraficaGoteo({ lecturas, desactualizado = false }) {
  const idTitulo = useId();
  const idResumen = useId();

  const { puntos, descartadas } = useMemo(() => prepararPuntos(lecturas), [lecturas]);
  const hayTendencia = puntos.length >= 2;

  function renderizarContenido() {
    if (puntos.length === 0) {
      return (
        <p className="grafica-goteo-estado">
          La gráfica aparecerá en cuanto el dispositivo envíe la primera lectura.
        </p>
      );
    }

    if (puntos.length === 1) {
      return (
        <p className="grafica-goteo-estado">
          Hay una sola lectura: {formatearGotas(puntos[0].gotas)} a las{" "}
          {formatearHora(puntos[0].t)}. Se necesitan al menos dos para trazar la
          tendencia.
        </p>
      );
    }

    const resumen = resumir(puntos);
    const marcasY = calcularMarcasY(resumen.maximo);

    return (
      <>
        {}
        <p id={idResumen} className="grafica-goteo-resumen">
          {puntos.length} lecturas entre las {resumen.desde} y las {resumen.hasta}.
          Mínimo {formatearGotas(resumen.minimo)}, máximo{" "}
          {formatearGotas(resumen.maximo)}, última {formatearGotas(resumen.ultimo)}.
        </p>

        <div className="grafica-goteo-lienzo">
          <ResponsiveContainer width="100%" height="100%">
            <LineChart
              data={puntos}
              margin={{ top: 8, right: 16, bottom: 4, left: 0 }}
              accessibilityLayer
            >
              <CartesianGrid stroke={COLOR_REJILLA} strokeDasharray="3 3" vertical={false} />

              {}
              <XAxis
                dataKey="t"
                type="number"
                scale="time"
                domain={["dataMin", "dataMax"]}
                padding={{ left: 16, right: 16 }}
                tickFormatter={formatearHora}
                minTickGap={32}
                tick={{ fontSize: 11, fill: COLOR_TEXTO_EJE }}
                stroke={COLOR_EJE}
              />

              {}
              <YAxis
                width={44}
                domain={[0, marcasY[marcasY.length - 1]]}
                ticks={marcasY}
                tick={{ fontSize: 11, fill: COLOR_TEXTO_EJE }}
                stroke={COLOR_EJE}
              />

              <Tooltip
                labelFormatter={formatearHora}
                formatter={(valor) => [formatearGotas(valor), "Goteo"]}
                separator=": "
              />

              {}
              <Line
                type="linear"
                dataKey="gotas"
                stroke={COLOR_LINEA}
                strokeWidth={2}
                dot={puntos.length <= MAXIMO_PUNTOS_VISIBLES}
                activeDot={{ r: 5 }}
                isAnimationActive={false}
              />
            </LineChart>
          </ResponsiveContainer>
        </div>
      </>
    );
  }

  return (
    <figure
      className="seccion-grafica"
      aria-labelledby={idTitulo}
      aria-describedby={hayTendencia ? idResumen : undefined}
    >
      <figcaption className="cabecera-historial">
        <span id={idTitulo} className="label-metrica">
          RITMO DE GOTEO EN EL TIEMPO
        </span>
        {}
        {desactualizado && (
          <span className="chip-desactualizado">
            Sin conexión · mostrando la última serie recibida
          </span>
        )}
      </figcaption>

      {renderizarContenido()}

      {descartadas > 0 && (
        <p className="grafica-goteo-nota">
          Se omitieron {descartadas} {descartadas === 1 ? "lectura" : "lecturas"} con
          hora o goteo inválidos, por eso la gráfica muestra menos puntos que la tabla.
        </p>
      )}
    </figure>
  );
}