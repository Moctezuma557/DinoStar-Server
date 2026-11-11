export function normalizar(texto) {
  return (texto ?? "")
    .normalize("NFD")
    .replace(/[̀-ͯ]/g, "")
    .toLowerCase()
    .trim();
}

export function contiene(texto, aguja) {
  const agujaLimpia = normalizar(aguja);
  if (!agujaLimpia) return true;
  return normalizar(texto).includes(agujaLimpia);
}
