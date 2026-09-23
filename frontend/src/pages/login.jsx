import React from 'react';
import './login.css';
import logo from '../assets/Logo.png';

export default function Login() {
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

          <form className="formulario-login" onSubmit={(e) => e.preventDefault()}>
            <div className="grupo-input">
              <label htmlFor="identificacion">Correo institucional</label>
              <input type="text" id="identificacion" placeholder="ej. enfermeria@hospital.org o ID 8829" />
            </div>

            <div className="grupo-input">
              <label htmlFor="contrasena">Contraseña</label>
              <input type="password" id="contrasena" placeholder="••••••••••••" />
            </div>

            <div className="grupo-casilla">
              <input type="checkbox" id="recordar" />
              <label htmlFor="recordar">Recordar terminal clínica</label>
            </div>

            <button type="submit" className="boton-enviar">
              Enviar
            </button>
          </form>
        </div>

      </div>
    </div>
  );
}