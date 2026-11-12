import type { Metadata } from 'next';
import { FormularioRestablecer } from './formulario-restablecer';

export const metadata: Metadata = {
  title: 'Crear contraseña nueva',
  // El enlace del correo lleva el token en el fragmento; además, no enviar Referer desde esta página.
  referrer: 'no-referrer',
};

export default function RestablecerContrasenaPage() {
  return (
    <>
      <h1 className="font-heading text-2xl font-bold text-panel-text">Crear contraseña nueva</h1>
      <p className="mt-1 text-sm text-panel-muted">Al guardarla se cerrarán todas tus sesiones abiertas.</p>
      <FormularioRestablecer />
    </>
  );
}
