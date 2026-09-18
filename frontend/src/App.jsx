import { BrowserRouter, Routes, Route } from 'react-router-dom';

// Importamos 
import Login from './pages/login';
import Dashboard from './pages/dashboard';
import Paciente from './pages/paciente';

function App() {
  return (
    <BrowserRouter>
      <Routes>
        <Route path="/login" element={<Login />} />
        <Route path="/dashboard" element={<Dashboard />} />
        <Route path="/paciente/:id" element={<Paciente />} />
      </Routes>
    </BrowserRouter>
  );
}

export default App;