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

import Login from "./pages/login";
import Dashboard from "./pages/dashboard";
import Paciente from "./pages/paciente";
import Pacientes from "./pages/pacientes";
import Dispositivos from "./pages/dispositivos"; 

// Datos temporales hasta que exista la autenticación real.
const enfermeraDemo = {
  nombre: "Enf. M. Arismendi",
  estado: "Activo",
  unidad: "Medicina Interna",
};


function AppShell() {
  const { pathname } = useLocation();
  const navigate = useNavigate();

  const [cantidadAlertas, setCantidadAlertas] = useState(0);
  const [triggerAlerta, setTriggerAlerta] = useState(0);

  const vistaActual = navigationItems.find((item) =>
    pathname.startsWith(item.activePrefix ?? item.to)
  );

  return (
    <DashboardLayout
      title={vistaActual?.label ?? "DinoStar"}
      activeItem={vistaActual?.id}
      user={enfermeraDemo}
      badges={{ alertasActivas: cantidadAlertas }}
      alertas={cantidadAlertas}  
      onNavigate={(_id, to) => navigate(to)}
      onAlertas={() => {
        navigate("/dashboard");
        setTriggerAlerta((prev) => prev + 1);
      }}
      onLogout={() => navigate("/login")}
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
      <Routes>
        <Route path="/login" element={<Login />} />

        <Route path="/" element={<Navigate to="/dashboard" replace />} />

        {/* Dentro del layout principal */}
        <Route element={<AppShell />}>
          <Route path="/dashboard" element={<Dashboard />} />
          <Route path="/pacientes" element={<Pacientes />} />
          <Route path="/paciente/:id" element={<Paciente />} />
          
          {/* NUEVA RUTA DE DISPOSITIVOS Y LOGS */}
          <Route path="/dispositivos" element={<Dispositivos />} />

          <Route path="*" element={<VistaEnConstruccion />} />
        </Route>
      </Routes>
    </BrowserRouter>
  );
}