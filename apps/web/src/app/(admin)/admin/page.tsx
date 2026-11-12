import Link from 'next/link';
import { redirect } from 'next/navigation';
import { ArrowRight, GraduationCap, ServerCog, ShieldAlert } from 'lucide-react';
import { Suspense } from 'react';
import { BotonCerrarSesion } from '@/components/shared/boton-cerrar-sesion';
import { obtenerSesion } from '@/lib/panel/sesion';

export const metadata = { title: 'Mis paneles' };

/** Entrada común: con un solo panel permitido va directo a él; con ambos, ofrece elegir. */
export default function AdminInicioPage() {
  return (
    <div className="flex min-h-screen items-center justify-center bg-gradient-to-br from-academico-50 via-panel-bg to-servicios-50 px-4 py-10">
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
  const { academico, servicios } = sesion.paneles;
  if (academico && !servicios) redirect('/admin/academico');
  if (servicios && !academico) redirect('/admin/servicios');

  return (
    <div className="w-full max-w-3xl">
      <div className="mb-8 flex flex-wrap items-end justify-between gap-4">
        <div>
          <p className="text-sm text-panel-muted">Horus Group SRL</p>
          <h1 className="font-heading text-2xl font-bold text-panel-text">Hola, {sesion.usuario.nombre}</h1>
          <p className="mt-1 text-sm text-panel-muted">Elige el panel con el que quieres trabajar.</p>
        </div>
        <BotonCerrarSesion className="text-panel-muted hover:bg-panel-surface hover:text-panel-text focus-visible:outline-academico-700" />
      </div>

      {academico || servicios ? (
        <ul className="grid gap-4 sm:grid-cols-2">
          {academico ? (
            <li>
              <Link
                href="/admin/academico"
                className="group flex h-full flex-col rounded-2xl border border-panel-border bg-panel-surface p-6 shadow-sm outline-offset-4 transition hover:border-academico-300 hover:shadow-md focus-visible:outline-2 focus-visible:outline-academico-700"
              >
                <span className="mb-4 flex h-11 w-11 items-center justify-center rounded-xl bg-academico-100 text-academico-800">
                  <GraduationCap className="h-6 w-6" aria-hidden="true" />
                </span>
                <span className="font-heading text-lg font-semibold text-panel-text">Gestión académica</span>
                <span className="mt-1 flex-1 text-sm text-panel-muted">Estudiantes, cursos, matrículas y reportes académicos.</span>
                <span className="mt-4 inline-flex items-center gap-1 text-sm font-semibold text-academico-700">
                  Entrar <ArrowRight className="h-4 w-4 transition group-hover:translate-x-0.5" aria-hidden="true" />
                </span>
              </Link>
            </li>
          ) : null}
          {servicios ? (
            <li>
              <Link
                href="/admin/servicios"
                className="group flex h-full flex-col rounded-2xl border border-panel-border bg-panel-surface p-6 shadow-sm outline-offset-4 transition hover:border-servicios-300 hover:shadow-md focus-visible:outline-2 focus-visible:outline-servicios-700"
              >
                <span className="mb-4 flex h-11 w-11 items-center justify-center rounded-xl bg-servicios-100 text-servicios-800">
                  <ServerCog className="h-6 w-6" aria-hidden="true" />
                </span>
                <span className="font-heading text-lg font-semibold text-panel-text">Servicios tecnológicos</span>
                <span className="mt-1 flex-1 text-sm text-panel-muted">Servicios, cotizaciones y gestión comercial.</span>
                <span className="mt-4 inline-flex items-center gap-1 text-sm font-semibold text-servicios-700">
                  Entrar <ArrowRight className="h-4 w-4 transition group-hover:translate-x-0.5" aria-hidden="true" />
                </span>
              </Link>
            </li>
          ) : null}
        </ul>
      ) : (
        <div role="alert" className="flex gap-3 rounded-2xl border border-panel-border bg-panel-surface p-6 text-sm text-panel-text">
          <ShieldAlert className="h-5 w-5 shrink-0 text-estado-aviso" aria-hidden="true" />
          Tu cuenta no tiene acceso a ningún panel. Contacta con un administrador.
        </div>
      )}
    </div>
  );
}
