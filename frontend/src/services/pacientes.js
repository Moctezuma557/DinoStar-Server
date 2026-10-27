import { pedirJson } from "./api";

/**
 * Traduce un paciente como lo devuelve la API al objeto que consumen
 * los componentes.
 *
 * Es el ÚNICO lugar del frontend que conoce los nombres de campo del
 * backend. Si mañana se renombra una columna o se agrega un envoltorio,
 * se cambia aquí y la interfaz no se entera.
 *
 * Campos que devuelve GET /api/pacientes, según el contrato de la #68:
 *   id · nombre · numero_cama · sala · tipo_solucion · modo_goteo
 *   vol_total · enfermera_id
 */
function adaptarPaciente(crudo) {
  return {
    id: crudo.id,
    nombre: crudo.nombre,
    cama: crudo.numero_cama,
    sala: crudo.sala,

    // Los registros históricos pueden tener tipo_solucion en null;
    // el contrato lo advierte. Sin este respaldo la tarjeta mostraría
    // la palabra "null" al usuario.
    solucion: crudo.tipo_solucion ?? "Sin solución registrada",

    modoGoteo: crudo.modo_goteo,

    // Laravel serializa las columnas decimales como texto: vol_total
    // llega como "500.00", no como 500. Sin esta conversión, cualquier
    // suma o comparación daría un resultado equivocado y en silencio.
    volTotal: Number(crudo.vol_total),

    enfermeraId: crudo.enfermera_id,

    /**
     * Hoy el endpoint solo devuelve pacientes con sesión ACTIVA, así
     * que esto siempre resulta verdadero. Se deja calculado y no fijo
     * en true para que, si el backend empieza a incluir pacientes sin
     * sesión, la interfaz los distinga sin cambios: los campos de
     * sesión llegarían ausentes.
     */
    tieneDispositivo: Boolean(crudo.modo_goteo),
  };
}

/**
 * Consulta el listado de pacientes activos con sesión.
 *
 * @param {string} nombre      · filtro parcial por nombre
 * @param {string|number} enfermeraId · filtro por enfermera a cargo
 * @param {AbortSignal} signal · para cancelar la petición en curso
 */
export async function obtenerPacientes({ nombre, enfermeraId, signal } = {}) {
  const respuesta = await pedirJson("/api/pacientes", {
    // La API espera enfermera_id en snake_case; dentro del frontend
    // la variable se llama enfermeraId. La traducción vive aquí.
    params: { nombre, enfermera_id: enfermeraId },
    signal,
  });

  // El contrato envuelve la lista en "data". Se acepta también el
  // arreglo suelto para no depender de ese detalle.
  const lista = Array.isArray(respuesta) ? respuesta : (respuesta?.data ?? []);

  return lista.map(adaptarPaciente);
}
