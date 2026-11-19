import type { Metadata } from 'next';
import { FormularioRecuperar } from './formulario-recuperar';

export const metadata: Metadata = { title: 'Recuperar contraseña' };

export default function RecuperarContrasenaPage() {
  return (
    <>
      <p className="text-sm font-medium text-oro-700">Sin problema</p>
      <h1 className="mt-1 font-heading text-3xl font-bold tracking-tight text-tinta">Recupera tu contraseña</h1>
      <p className="mt-2 text-sm leading-6 text-panel-muted">
        Escribe tu correo y te enviaremos un enlace para crear una contraseña nueva.
      </p>
      <FormularioRecuperar />
    </>
  );
}
