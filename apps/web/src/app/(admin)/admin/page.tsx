import { redirect } from 'next/navigation';
import { ShieldAlert } from 'lucide-react';
import { Suspense } from 'react';
import { BotonCerrarSesion } from '@/components/shared/boton-cerrar-sesion';
import { obtenerSesion } from '@/lib/panel/sesion';

// Título neutro: esta página solo resuelve la sesión y redirige; no debe mostrarse como una pantalla propia.
export const metadata = { title: 'Horus Group' };

/** Entrada común: quien tiene acceso al panel académico va directo; el resto ve un aviso. */
export default function AdminInicioPage() {
  return (
    <div className="flex min-h-screen items-center justify-center bg-gradient-to-br from-academico-50 via-panel-bg to-academico-100 px-4 py-10">
      <Suspense fallback={<div className="h-72 w-full max-w-3xl animate-pulse rounded-2xl bg-panel-surface" aria-busy="true" />}>
        <SelectorPanel />
      </Suspense>
    </div>
  );
}

async function SelectorPanel() {
  const resultado = await obtenerSesion();
  if (resultado.estado === 'anonimo') redirect('/login');
  if (resultado.estado === 'no_disponible') {
    return (
      <p role="alert" className="rounded-xl border border-panel-border bg-panel-surface p-6 text-sm text-panel-text">
        El panel no está disponible en este momento. Inténtalo de nuevo en unos minutos.
      </p>
    );
  }

  const { sesion } = resultado;
  if (sesion.paneles.academico) redirect('/admin/academico');

  return (
    <div className="w-full max-w-3xl">
      <div className="mb-8 flex flex-wrap items-end justify-between gap-4">
        <div>
          <p className="text-sm text-panel-muted">Horus Group SRL</p>
          <h1 className="font-heading text-2xl font-bold text-panel-text">Hola, {sesion.usuario.nombre}</h1>
        </div>
        <BotonCerrarSesion className="text-panel-muted hover:bg-panel-surface hover:text-panel-text focus-visible:outline-academico-700" />
      </div>
      <div role="alert" className="flex gap-3 rounded-2xl border border-panel-border bg-panel-surface p-6 text-sm text-panel-text">
        <ShieldAlert className="h-5 w-5 shrink-0 text-estado-aviso" aria-hidden="true" />
        Tu cuenta no tiene acceso al panel. Contacta con un administrador.
      </div>
    </div>
  );
}
