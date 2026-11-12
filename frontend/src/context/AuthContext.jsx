import {
  createContext,
  useCallback,
  useContext,
  useEffect,
  useRef,
  useState,
} from "react";
import {
  iniciarSesion as iniciarSesionEnApi,
  obtenerUsuarioActual,
  cerrarSesion as cerrarSesionEnApi,
} from "../services/auth";

const CLAVE_TOKEN = "dinostar.token";
const CLAVE_EXPIRA = "dinostar.expira";
const LIMITE_SETTIMEOUT = 2147483647;

function almacenPara(recordar, rol) {
  const puedeRecordar = recordar === true && rol === "ENFERMERA";
  return puedeRecordar ? window.localStorage : window.sessionStorage;
}

function leerSesionGuardada() {
  for (const almacen of [window.localStorage, window.sessionStorage]) {
    try {
      const token = almacen.getItem(CLAVE_TOKEN);
      if (token) {
        return { token, expiraEn: almacen.getItem(CLAVE_EXPIRA) };
      }
    } catch {
    }
  }
  return null;
}

function guardarSesion({ token, expiraEn, recordar, rol }) {
  borrarSesion();
  try {
    const almacen = almacenPara(recordar, rol);
    almacen.setItem(CLAVE_TOKEN, token);
    if (expiraEn) almacen.setItem(CLAVE_EXPIRA, expiraEn);
  } catch {
  }
}

function borrarSesion() {
  for (const almacen of [window.localStorage, window.sessionStorage]) {
    try {
      almacen.removeItem(CLAVE_TOKEN);
      almacen.removeItem(CLAVE_EXPIRA);
    } catch {
      // Ídem.
    }
  }
}


function estaExpirado(expiraEn) {
  if (!expiraEn) return false;
  const vence = new Date(expiraEn).getTime();
  if (Number.isNaN(vence)) return false;
  return vence <= Date.now();
}

const AuthContext = createContext(null);

export function useAuth() {
  const contexto = useContext(AuthContext);
  if (!contexto) {
    throw new Error("useAuth debe usarse dentro de <AuthProvider>");
  }
  return contexto;
}

export function AuthProvider({ children }) {
  const [usuario, setUsuario] = useState(null);
  const [token, setToken] = useState(null);
  const [restaurando, setRestaurando] = useState(true);

  const temporizadorRef = useRef(null);

  const salir = useCallback(
    async (tokenActual) => {
      if (temporizadorRef.current) {
        clearTimeout(temporizadorRef.current);
        temporizadorRef.current = null;
      }
      await cerrarSesionEnApi(tokenActual ?? token);
      borrarSesion();
      setUsuario(null);
      setToken(null);
    },
    [token]
  );

 
  const programarExpiracion = useCallback((expiraEn) => {
    if (temporizadorRef.current) clearTimeout(temporizadorRef.current);
    if (!expiraEn) return;

    const vence = new Date(expiraEn).getTime();
    if (Number.isNaN(vence)) return;

    const rearmar = () => {
      const faltan = vence - Date.now();

      if (faltan <= 0) {
        borrarSesion();
        setUsuario(null);
        setToken(null);
        return;
      }

      temporizadorRef.current = setTimeout(
        rearmar,
        Math.min(faltan, LIMITE_SETTIMEOUT)
      );
    };

    rearmar();
  }, []);
  useEffect(() => {
    let activo = true;

    async function restaurar() {
      const guardada = leerSesionGuardada();

      if (!guardada) {
        if (activo) setRestaurando(false);
        return;
      }

      if (estaExpirado(guardada.expiraEn)) {
        borrarSesion();
        if (activo) setRestaurando(false);
        return;
      }

      try {
        const usuarioActual = await obtenerUsuarioActual(guardada.token);
        if (!activo) return;

        setUsuario(usuarioActual);
        setToken(guardada.token);
        programarExpiracion(guardada.expiraEn);
      } catch (fallo) {
        if (!activo) return;
        borrarSesion();
      } finally {
        if (activo) setRestaurando(false);
      }
    }

    restaurar();

    return () => {
      activo = false;
      if (temporizadorRef.current) clearTimeout(temporizadorRef.current);
    };
  }, [programarExpiracion]);

  const entrar = useCallback(
    async ({ email, password, recordar }) => {
      const sesion = await iniciarSesionEnApi({ email, password, recordar });

      guardarSesion({
        token: sesion.token,
        expiraEn: sesion.expiraEn,
        recordar: sesion.recordar,
        rol: sesion.usuario?.rol,
      });

      setUsuario(sesion.usuario);
      setToken(sesion.token);
      programarExpiracion(sesion.expiraEn);

      return sesion.usuario;
    },
    [programarExpiracion]
  );

  const valor = {
    usuario,
    token,
    rol: usuario?.rol ?? null,
    autenticado: Boolean(usuario),
    restaurando,
    entrar,
    salir,
  };

  return <AuthContext.Provider value={valor}>{children}</AuthContext.Provider>;
}