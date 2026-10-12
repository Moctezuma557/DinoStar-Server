import { useNavigate } from "react-router-dom";
import LocalizadorPacientes from "../components/LocalizadorPacientes";

export default function Pacientes() {
  const navigate = useNavigate();

  return (
    <LocalizadorPacientes
      onCerrar={() => navigate("/dashboard")}
      onConfirmar={(paciente) => navigate(`/paciente/${paciente.id}`)}
    />
  );
}