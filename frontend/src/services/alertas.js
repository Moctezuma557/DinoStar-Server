const API_URL = (
  import.meta.env.VITE_API_URL ?? "http://localhost:8000"
).replace(/\/$/, "");

export async function getAlertasActivas() {
  const respuesta = await fetch(`${API_URL}/api/alertas?resuelta=false`, {
    headers: { Accept: "application/json" },
  });
  if (!respuesta.ok) throw new Error("Error al obtener alertas");
  const cuerpo = await respuesta.json();
  return Array.isArray(cuerpo) ? cuerpo : cuerpo.data ?? [];
}

export async function resolverAlerta(id, enfermeraId) {
  const idValido = Number(enfermeraId) || -35001; // Usamos -35001 como ID de enfermera válido por defecto según tu BD

  const payload = {
    resuelta: true,
    enfermera_id: idValido,
  };

  const respuesta = await fetch(`${API_URL}/api/alertas/${id}`, {
    method: "PATCH",
    headers: {
      "Content-Type": "application/json",
      "Accept": "application/json",
    },
    body: JSON.stringify(payload),
  });

  if (respuesta.status === 409) {
    const error = new Error("La alerta ya fue resuelta previamente.");
    error.status = 409;
    throw error;
  }

  if (!respuesta.ok) {
    const errorData = await respuesta.json().catch(() => ({}));
    console.error("Detalle del error del backend:", errorData);
    throw new Error(`Error ${respuesta.status} al resolver la alerta`);
  }

  return await respuesta.json();
}