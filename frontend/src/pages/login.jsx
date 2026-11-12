import { useState } from 'react';
import { Navigate, useNavigate } from 'react-router-dom';
import { useAuth } from '../context/AuthContext';
import './login.css';
import logo from '../assets/Logo.png';

export default function Login() {
  const navigate = useNavigate();
  const { entrar, autenticado, restaurando } = useAuth();

  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [recordar, setRecordar] = useState(false);
  const [enviando, setEnviando] = useState(false);
  const [error, setError] = useState(null);
  if (restaurando) {
    return (
      <div className="contenedor-login">
        <p className="login-restaurando">Restaurando sesión…</p>
      </div>
    );
  }

  if (autenticado) {
    return <Navigate to="/dashboard" replace />;
  }

  async function alEnviar(evento) {
    evento.preventDefault();
    if (enviando) return;

    setError(null);
    setEnviando(true);

    try {
      await entrar({ email: email.trim(), password, recordar });
      navigate('/dashboard', { replace: true });
    } catch (fallo) {
      setError(fallo.message);
      setPassword('');
    } finally {
      setEnviando(false);
    }
  }

  return (
    <div className="contenedor-login">
      <div className="tarjeta-login">

        <div className="panel-izquierdo">
          <div className="contenido-izquierdo">
            <h1>Cuidado inteligente con calidez humana.</h1>
            <p>Monitoreo continuo, control volumétrico de venoclisis y alertas instantáneas.</p>
          </div>
        </div>

        <div className="panel-derecho">
          <div className="encabezado-formulario">
            <div className="marcador-logo" >
              <img src={logo} alt="Logo DinoStar" className="logo-imagen" />
            </div>
            <h2>Iniciar Sesión</h2>
            <p>Ingresa tus credenciales autorizadas del personal médico o de enfermería.</p>
          </div>

          <form className="formulario-login" onSubmit={alEnviar} noValidate>
            {}
            {error && (
              <p className="login-error" role="alert">
                {error}
              </p>
            )}

            <div className="grupo-input">
              <label htmlFor="identificacion">Correo institucional</label>
              <input
                type="email"
                id="identificacion"
                name="email"
                autoComplete="username"
                placeholder="ej. enfermeria@hospital.org"
                value={email}
                onChange={(evento) => setEmail(evento.target.value)}
                disabled={enviando}
                required
              />
            </div>

            <div className="grupo-input">
              <label htmlFor="contrasena">Contraseña</label>
              <input
                type="password"
                id="contrasena"
                name="password"
                autoComplete="current-password"
                placeholder="••••••••••••"
                value={password}
                onChange={(evento) => setPassword(evento.target.value)}
                disabled={enviando}
                required
              />
            </div>

            <div className="grupo-casilla">
              <input
                type="checkbox"
                id="recordar"
                checked={recordar}
                onChange={(evento) => setRecordar(evento.target.checked)}
                disabled={enviando}
              />
              <label htmlFor="recordar">Recordar terminal clínica</label>
            </div>

            {}
            <p className="login-nota-recordar">
              Disponible solo para personal de enfermería.
            </p>

            <button
              type="submit"
              className="boton-enviar"
              disabled={enviando || email.trim() === '' || password === ''}
            >
              {enviando ? 'Verificando…' : 'Enviar'}
            </button>
          </form>
        </div>

      </div>
    </div>
  );
}