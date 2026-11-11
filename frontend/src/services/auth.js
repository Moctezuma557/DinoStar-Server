const API_URL = (
  import.meta.env.VITE_API_URL ?? "http://localhost:8000"
).replace(/\/$/, "");
export class ErrorDeAutenticacion extends Error {
  constructor(mensaje, estado, codigo) {
    super(mensaje);
    this.name = "ErrorDeAutenticacion";
    this.estado = estado;
    this.codigo = codigo;
  }
}

function mensajeParaUsuario(estado, mensajeBackend) {
  if (estado === 401) return "Correo o contraseña incorrectos.";
  if (estado === 403) return "Esta cuenta no tiene acceso al sistema.";
  if (estado === 422) return mensajeBackend || "Revisa los datos ingresados.";
  if (estado === 429) {
    return "Demasiados intentos. Espera un momento antes de volver a intentar.";
  }
  return "No se pudo contactar al servidor. Revisa la conexión.";
}

async function leerCuerpo(respuesta) {
  try {
    return await respuesta.json();
  } catch {
    return {};
  }
}

/**
 * @param {string} email
 * @param {string} password
 * @param {boolean} recordar
 * @returns {Promise<{token: string, expiraEn: string, recordar: boolean, usuario: object}>}
 */
export async function iniciarSesion({ email, password, recordar = false }) {
  let respuesta;

  try {
    respuesta = await fetch(`${API_URL}/api/login`, {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
        Accept: "application/json",
      },
      body: JSON.stringify({ email, password, recordar }),
    });
  } catch (fallo) {
    throw new ErrorDeAutenticacion(mensajeParaUsuario(0), 0, "sin_conexion");
  }

  const cuerpo = await leerCuerpo(respuesta);

  if (!respuesta.ok) {
    throw new ErrorDeAutenticacion(
      mensajeParaUsuario(respuesta.status, cuerpo?.message),
      respuesta.status,
      cuerpo?.code ?? null,
    );
  }

  const datos = cuerpo?.data ?? cuerpo;

  return {
    token: datos.token,
    expiraEn: datos.expires_at ?? null,
    recordar: Boolean(datos.recordar),

    usuario: datos.usuario,
  };
}

export async function obtenerUsuarioActual(token) {
  let respuesta;

  try {
    respuesta = await fetch(`${API_URL}/api/me`, {
      headers: {
        Accept: "application/json",
        Authorization: `Bearer ${token}`,
      },
    });
  } catch {
    throw new ErrorDeAutenticacion(mensajeParaUsuario(0), 0, "sin_conexion");
  }

  if (!respuesta.ok) {
    const cuerpo = await leerCuerpo(respuesta);
    throw new ErrorDeAutenticacion(
      mensajeParaUsuario(respuesta.status, cuerpo?.message),
      respuesta.status,
      cuerpo?.code ?? null,
    );
  }

  const cuerpo = await leerCuerpo(respuesta);
  return cuerpo?.data ?? cuerpo;
}

export async function cerrarSesion(token) {
  if (!token) return;

  try {
    await fetch(`${API_URL}/api/logout`, {
      method: "POST",
      headers: {
        Accept: "application/json",
        Authorization: `Bearer ${token}`,
      },
    });
  } catch {}
}
