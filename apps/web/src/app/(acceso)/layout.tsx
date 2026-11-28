import type { Metadata } from 'next';
import type { ReactNode } from 'react';
import { MarcaPanel } from '@/components/shared/marca-panel';
import { CabeceraMovilAcceso, PanelMarcaAcceso } from './contexto-acceso';

/*
 * Acceso al panel (login, registro y recuperación de contraseña), fuera de /admin.
 *
 * `data-panel-root` activa el aislamiento del tema público (globals.css): estas páginas no muestran la barra,
 * el pie ni el chat públicos.
 *
 * Diseño: pórtico split-viewport limpio (borde a borde) con paleta Azul + Ámbar (60/30/10):
 * columna institucional azul profundo con contexto dinámico por ruta y superficie clara de trabajo.
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
      className="min-h-svh bg-[#F8FAFC] text-panel-text antialiased lg:grid lg:grid-cols-[minmax(360px,42%)_1fr]"
    >
      <PanelMarcaAcceso />

      <div className="flex min-h-svh flex-col lg:min-h-0">
        <CabeceraMovilAcceso />

        <div className="flex flex-1 items-center justify-center px-5 py-8 sm:px-10 sm:py-12 lg:px-14">
          <div className="acceso-tarjeta w-full max-w-[420px]">{children}</div>
        </div>

        <p className="border-t border-panel-border/70 px-5 py-4 text-center text-xs text-panel-muted lg:hidden">
          © Horus Group SRL · Cajamarca, Perú
        </p>
      </div>
    </MarcaPanel>
  );
}
