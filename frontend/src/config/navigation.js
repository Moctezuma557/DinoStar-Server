export const navigationItems = [
  {
    id: "panel",
    label: "Panel Principal",
    to: "/dashboard",
    icono: "cuadricula",
  },
  {
    id: "pacientes",
    label: "Pacientes",
    to: "/pacientes",
    icono: "usuarios",
    activePrefix: "/paciente",
  },
  {
    id: "reportes",
    label: "Reportes",
    to: "/reportes",
    icono: "reporte",
  },
  {
    id: "dispositivos",
    label: "Dispositivos",
    to: "/dispositivos",
    icono: "monitor",
  },
  {
    id: "alertas",
    label: "Alertas",
    to: "/alertas",
    icono: "campana",
    badge: "alertasActivas",
  },
];
