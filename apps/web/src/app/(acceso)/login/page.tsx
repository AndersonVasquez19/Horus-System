import type { Metadata } from 'next';
import Link from 'next/link';
import { Suspense } from 'react';
import { FormularioLogin } from './formulario-login';

export const metadata: Metadata = { title: 'Iniciar sesión' };

export default function LoginPage() {
  return (
    <>
      <p className="text-sm font-medium text-oro-700">Qué bueno verte de nuevo</p>
      <h1 className="mt-1 font-heading text-3xl font-bold tracking-tight text-tinta">Inicia sesión</h1>
      <p className="mt-2 text-sm leading-6 text-panel-muted">Entra al panel con tu correo institucional y tu contraseña.</p>
      {/* useSearchParams (destino tras el acceso) se resuelve en la petición. */}
      <Suspense fallback={<div className="mt-6 h-64 animate-pulse rounded-lg bg-panel-bg" aria-hidden="true" />}>
        <FormularioLogin />
      </Suspense>
      <p className="mt-5 text-center text-sm text-panel-muted">
        ¿Aún no tienes cuenta?{' '}
        <Link href="/registro" className="rounded font-medium text-academico-700 underline-offset-4 outline-offset-2 hover:underline focus-visible:outline-2 focus-visible:outline-oro-500">
          Crea una
        </Link>
      </p>
    </>
  );
}
