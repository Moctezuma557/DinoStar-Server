import { useEffect, useState } from "react";

/**
 * @param {*} valor
 * @param {number} retraso
 */
export function useDebounce(valor, retraso = 350) {
  const [valorDiferido, setValorDiferido] = useState(valor);

  useEffect(() => {
    const id = setTimeout(() => setValorDiferido(valor), retraso);
    return () => clearTimeout(id);
  }, [valor, retraso]);

  return valorDiferido;
}
