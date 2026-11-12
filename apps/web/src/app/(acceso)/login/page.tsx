import type { Metadata } from 'next';
import { Suspense } from 'react';
import { FormularioLogin } from './formulario-login';

export const metadata: Metadata = { title: 'Iniciar sesión' };

export default function LoginPage() {
  return (
    <>
      <h1 className="font-heading text-2xl font-bold text-panel-text">Iniciar sesión</h1>
      <p className="mt-1 text-sm text-panel-muted">Usa tu correo institucional y tu contraseña.</p>
      {/* useSearchParams (destino tras el acceso) se resuelve en la petición. */}
      <Suspense fallback={<div className="mt-6 h-64 animate-pulse rounded-lg bg-panel-bg" aria-hidden="true" />}>
        <FormularioLogin />
      </Suspense>
    </>
  );
}
