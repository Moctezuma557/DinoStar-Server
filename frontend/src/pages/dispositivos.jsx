import React, { useState, useEffect } from "react";
import "./dispositivos.css";

function formatearFecha(iso) {
  if (!iso) return "—";
  const fecha = new Date(iso);
  if (Number.isNaN(fecha.getTime())) return "—";
  return fecha.toLocaleString("es-MX", {
    year: "numeric",
    month: "2-digit",
    day: "2-digit",
    hour: "2-digit",
    minute: "2-digit",
    second: "2-digit",
    hour12: false,
  });
}

export default function Dispositivos() {
  const [logs, setLogs] = useState([]);
  const [selectedLog, setSelectedLog] = useState(null);
  const [cargando, setCargando] = useState(true);
  const [error, setError] = useState(null);

 useEffect(() => {
    fetchLogs(); // Carga inicial

    // Configurar sondeo (polling) cada 5 segundos
    const intervalo = setInterval(() => {
      fetchLogs(true); // Pasamos true opcionalmente para que no muestre el pantallazo de "Cargando..."
    }, 500);

    // Limpiar el intervalo cuando el usuario cambie de página (desmontar componente)
    return () => clearInterval(intervalo);
  }, []);

  // Modifica ligeramente tu función fetchLogs para evitar parpadeos molestos:
  const fetchLogs = async (esSilencioso = false) => {
    try {
      if (!esSilencioso) setCargando(true);
      const token = localStorage.getItem("token"); // O la forma en que manejes la sesión en DinoStar
      const response = await fetch("http://127.0.0.1:8000/api/dispositivos/logs", {
            headers: {
              "Content-Type": "application/json",
              "Authorization": `Bearer ${token}`
            },
          });

      if (!response.ok) {
        if (response.status === 403) {
          throw new Error("No autorizado. Se requiere rol de administrador (403).");
        }
        throw new Error("Acceso permitido solamente a personal autorizado.");
      }

      const data = await response.json();
      // Si usas paginación de Laravel, los datos vienen en data.data o directamente en data
      setLogs(data.data ?? data);
    } catch (err) {
      setError(err.message);
    } finally {
      setCargando(false);
    }
  };

  if (cargando) {
    return (
      <div className="contenedor-dispositivos-vista">
        <p className="estado-carga-vista">Cargando registros de dispositivos...</p>
      </div>
    );
  }

  if (error) {
    return (
      <div className="contenedor-dispositivos-vista">
        <div className="estado-error-vista" role="alert">
          <h2>Acceso Restringido</h2>
          <p>{error}</p>
        </div>
      </div>
    );
  }

  return (
    <div className="contenedor-dispositivos-vista">
      <div className="tarjeta-info-superior">
        <div className="detalles-principales-paciente">
          <div className="fila-titulo-paciente">
            <h2>Auditoría de Dispositivos</h2>
            <span className="insignia-permeable">
              ● Logs y Advertencias Relevantes
            </span>
          </div>
          <div className="subdetalles-paciente">
            <span>Monitoreo de sesiones erróneas e inconsistencias de datos</span>
          </div>
        </div>
      </div>

      <div className="tarjeta-infusion-principal">
        <div className="cabecera-historial">
          <span className="label-metrica">REGISTROS RELEVANTES DEL SISTEMA</span>
          <span className="chip-actualizacion">
            {logs.length} {logs.length === 1 ? "registro" : "registros"}
          </span>
        </div>

        <div className="envoltura-tabla-lecturas">
          <table className="tabla-lecturas">
            <thead>
              <tr>
                <th scope="col">ID</th>
                <th scope="col">Tipo</th>
                <th scope="col">Categoría</th>
                <th scope="col">Mensaje</th>
                <th scope="col">Fecha y Hora</th>
              </tr>
            </thead>
            <tbody>
              {logs.length === 0 ? (
                <tr>
                  <td colSpan="5" className="estado-vacio-historial">
                    No hay registros de dispositivos importantes en este momento.
                  </td>
                </tr>
              ) : (
                logs.map((log) => (
                  <tr 
                    key={log.id} 
                    onClick={() => setSelectedLog(log)}
                    className="fila-tabla-clicable"
                    style={{ cursor: "pointer" }}
                  >
                    <td>#{log.id}</td>
                    <td>
                      <span className={`badge-tipo ${log.tipo}`}>
                        {log.tipo}
                      </span>
                    </td>
                    <td>{log.categoria ?? "—"}</td>
                    <td className="truncar-texto">{log.mensaje ?? log.message}</td>
                    <td>{formatearFecha(log.created_at)}</td>
                  </tr>
                ))
              )}
            </tbody>
          </table>
        </div>
      </div>

      {/* Cuadro de diálogo / Modal de Detalles */}
      {selectedLog && (
        <div className="modal-overlay" onClick={() => setSelectedLog(null)}>
          <div className="modal-contenido" onClick={(e) => e.stopPropagation()}>
            <div className="modal-cabecera">
              <h3>Detalles del Log #{selectedLog.id}</h3>
              <button className="modal-boton-cerrar" onClick={() => setSelectedLog(null)}>
                &times;
              </button>
            </div>
            
            <div className="modal-cuerpo">
              <p><strong>Tipo:</strong> <span>{selectedLog.tipo}</span></p>
              <p><strong>Categoría:</strong> <span>{selectedLog.categoria ?? "—"}</span></p>
              <p><strong>Paciente ID:</strong> <span>{selectedLog.paciente_id ?? "N/A"}</span></p>
              <p><strong>Mensaje Completo:</strong></p>
              <div className="caja-codigo-modal">
                {selectedLog.mensaje ?? selectedLog.message}
              </div>
              <p><strong>Payload / Datos de Contexto:</strong></p>
              <pre className="caja-codigo-modal json">
                {JSON.stringify(selectedLog.payload ?? selectedLog.details, null, 2)}
              </pre>
              <p><strong>Registrado el:</strong> <span>{formatearFecha(selectedLog.created_at)}</span></p>
            </div>

            <div className="modal-footer">
              <button className="boton-volver" onClick={() => setSelectedLog(null)}>
                Cerrar
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}