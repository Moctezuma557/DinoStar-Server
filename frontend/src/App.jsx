import { useState } from "react";
import DashboardLayout from "./layouts/DashboardLayout";
import { navigationItems } from "./config/navigation";


export default function App() {
  const [activeItem, setActiveItem] = useState("panel");

  const vistaActual = navigationItems.find((item) => item.id === activeItem);

  const enfermeraDemo = {
    nombre: "Enf. M. Arismendi",
    turno: "matutino",
    unidad: "Medicina Interna",
  };

  return (
    <DashboardLayout
      title={vistaActual?.label}
      activeItem={activeItem}
      user={enfermeraDemo}
      badges={{ alertasActivas: 2 }}
      onNavigate={(id) => setActiveItem(id)}
      onLogout={() => console.log("cerrar sesión")}
    >
      <p>Área de contenido. Cada vista del sistema se renderiza aquí.</p>
    </DashboardLayout>
  );
}