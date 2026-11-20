import type { Metadata } from 'next';
import { FormularioRegistro } from './formulario-registro';

export const metadata: Metadata = { title: 'Crear cuenta' };

export default function RegistroPage() {
  return (
    <>
      <p className="text-sm font-medium text-oro-700">Empieza algo nuevo</p>
      <h1 className="mt-1 font-heading text-3xl font-bold tracking-tight text-tinta">Crea tu cuenta</h1>
      <p className="mt-2 text-sm leading-6 text-panel-muted">
        Tu cuenta se crea sin acceso al panel. Un administrador te lo dará cuando corresponda.
      </p>
      <FormularioRegistro />
    </>
  );
}
