export const navigationItems = [
  {
    id: "panel",
    label: "Panel Principal",
    to: "/dashboard",
  },
  {
    id: "pacientes",
    label: "Pacientes",
    to: "/pacientes",
    activePrefix: "/paciente",
  },
  {
    id: "reportes",
    label: "Reportes",
    to: "/reportes",
  },
  {
    id: "dispositivos",
    label: "Dispositivos",
    to: "/dispositivos",
  },
  {
    id: "alertas",
    label: "Alertas",
    to: "/alertas",
    badge: "alertasActivas",
  },
];
