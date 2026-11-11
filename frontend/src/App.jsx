import { useState } from "react";
import {
  BrowserRouter,
  Routes,
  Route,
  Navigate,
  Outlet,
  useLocation,
  useNavigate,
} from "react-router-dom";

import DashboardLayout from "./layouts/DashboardLayout";
import { navigationItems } from "./config/navigation";
import { AuthProvider, useAuth } from "./context/AuthContext";

import Login from "./pages/login";
import Dashboard from "./pages/dashboard";
import Paciente from "./pages/paciente";
import Pacientes from "./pages/pacientes";
import Dispositivos from "./pages/dispositivos";

const PROTEGER_RUTAS = true;

const NOMBRES_DE_ROL = {
  ENFERMERA: "Enfermera",
  ADMIN: "Administrador",
};


function capitalizar(texto) {
  if (!texto) return "";
  return texto.charAt(0) + texto.slice(1).toLowerCase();
}

function usuarioParaEncabezado(usuario) {
  if (!usuario) {
    return { nombre: "Sin sesión", estado: "—", unidad: "—" };
  }

  const rol = NOMBRES_DE_ROL[usuario.rol] ?? capitalizar(usuario.rol);
  const turno = capitalizar(usuario.turno);

  return {
    id: usuario.id,
    nombre: usuario.nombre,
    estado: usuario.activo ? "Activo" : "Inactivo",
    unidad: turno ? `${rol} · ${turno}` : rol,
  };
}

function PantallaRestaurando() {
  return (
    <div style={{ display: "grid", placeItems: "center", minHeight: "100vh" }}>
      <p>Restaurando sesión…</p>
    </div>
  );
}

function RutaProtegida({ children }) {
  const { autenticado, restaurando } = useAuth();
  const location = useLocation();

  if (!PROTEGER_RUTAS) return children;
  if (restaurando) return <PantallaRestaurando />;

  if (!autenticado) {
    return <Navigate to="/login" replace state={{ desde: location.pathname }} />;
  }

  return children;
}

function AppShell() {
  const { pathname } = useLocation();
  const navigate = useNavigate();
  const { usuario, salir } = useAuth();

  const [cantidadAlertas, setCantidadAlertas] = useState(0);
  const [triggerAlerta, setTriggerAlerta] = useState(0);

  const vistaActual = navigationItems.find((item) =>
    pathname.startsWith(item.activePrefix ?? item.to)
  );

  async function cerrarSesion() {
    await salir();
    navigate("/login", { replace: true });
  }

  return (
    <DashboardLayout
      title={vistaActual?.label ?? "DinoStar"}
      activeItem={vistaActual?.id}
      user={usuarioParaEncabezado(usuario)}
      badges={{ alertasActivas: cantidadAlertas }}
      alertas={cantidadAlertas}
      onNavigate={(_id, to) => navigate(to)}
      onAlertas={() => {
        navigate("/dashboard");
        setTriggerAlerta((prev) => prev + 1);
      }}
      onLogout={cerrarSesion}
    >
      <Outlet context={{ setCantidadAlertas, triggerAlerta }} />
    </DashboardLayout>
  );
}


function VistaEnConstruccion() {
  return <p>Esta vista todavía no está disponible.</p>;
}

export default function App() {
  return (
    <BrowserRouter>
      <AuthProvider>
        <Routes>
          <Route path="/login" element={<Login />} />

          <Route path="/" element={<Navigate to="/dashboard" replace />} />

          {}
          <Route
            element={
              <RutaProtegida>
                <AppShell />
              </RutaProtegida>
            }
          >
            <Route path="/dashboard" element={<Dashboard />} />
            <Route path="/pacientes" element={<Pacientes />} />
            <Route path="/paciente/:id" element={<Paciente />} />

            {}
            <Route path="/dispositivos" element={<Dispositivos />} />

            <Route path="*" element={<VistaEnConstruccion />} />
          </Route>
        </Routes>
      </AuthProvider>
    </BrowserRouter>
  );
}