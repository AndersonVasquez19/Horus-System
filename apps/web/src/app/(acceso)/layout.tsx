import type { Metadata } from 'next';
import type { ReactNode } from 'react';
import { MarcaPanel } from '@/components/shared/marca-panel';

/*
 * Acceso al panel (login y recuperación de contraseña), fuera de /admin.
 *
 * `data-panel-root` activa el aislamiento del tema público (globals.css): estas páginas no muestran la barra,
 * el pie ni el chat públicos y usan el tema claro del panel.
 */
export const metadata: Metadata = {
  title: { default: 'Acceso al panel', template: '%s | Panel Horus' },
  description: 'Acceso al panel administrativo interno de Horus Group SRL.',
  keywords: [],
  openGraph: { type: 'website', siteName: 'Horus Group SRL', locale: 'es_PE', title: 'Acceso al panel' },
  robots: { index: false, follow: false, nocache: true, googleBot: { index: false, follow: false, noimageindex: true } },
};

export default function AccesoLayout({ children }: { children: ReactNode }) {
  return (
    <MarcaPanel
      atributo="data-panel-root"
      className="flex min-h-screen items-center justify-center bg-gradient-to-br from-academico-50 via-panel-bg to-servicios-50 px-4 py-10 text-panel-text antialiased"
    >
      <div className="w-full max-w-md">
        <div className="mb-6 text-center">
          <p className="font-heading text-lg font-bold tracking-tight text-panel-text">Horus Group SRL</p>
          <p className="text-sm text-panel-muted">Panel administrativo</p>
        </div>
        <div className="rounded-2xl border border-panel-border bg-panel-surface p-6 shadow-xl shadow-slate-200/60 sm:p-8">
          {children}
        </div>
        <p className="mt-6 text-center text-xs text-panel-muted">Acceso exclusivo para personal autorizado.</p>
      </div>
    </MarcaPanel>
  );
}
