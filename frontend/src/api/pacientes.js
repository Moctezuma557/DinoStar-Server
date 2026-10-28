const API_URL = (
  import.meta.env.VITE_API_URL ?? "http://localhost:8000"
).replace(/\/$/, "");

export async function getPacientes() {
  const respuesta = await fetch(`${API_URL}/api/pacientes`, {
    headers: { Accept: "application/json" },
  });

  if (!respuesta.ok) {
    throw new Error(`Error ${respuesta.status} al cargar pacientes`);
  }

  const cuerpo = await respuesta.json();
  return Array.isArray(cuerpo) ? cuerpo : cuerpo.data ?? [];
}
