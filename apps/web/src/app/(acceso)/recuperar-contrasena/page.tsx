import type { Metadata } from 'next';
import { FormularioRecuperar } from './formulario-recuperar';

export const metadata: Metadata = { title: 'Recuperar contraseña' };

export default function RecuperarContrasenaPage() {
  return (
    <>
      <h1 className="font-heading text-2xl font-bold text-panel-text">Recuperar contraseña</h1>
      <p className="mt-1 text-sm text-panel-muted">
        Escribe tu correo y te enviaremos un enlace para crear una contraseña nueva.
      </p>
      <FormularioRecuperar />
    </>
  );
}
