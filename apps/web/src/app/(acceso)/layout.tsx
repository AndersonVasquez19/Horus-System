import type { Metadata } from 'next';
import type { ReactNode } from 'react';
import { LogoHorus } from '@/components/shared/marca-horus';
import { MarcaPanel } from '@/components/shared/marca-panel';

/*
 * Acceso al panel (login y recuperación de contraseña), fuera de /admin.
 *
 * `data-panel-root` activa el aislamiento del tema público (globals.css): estas páginas no muestran la barra,
 * el pie ni el chat públicos.
 *
 * Diseño: sigue el lenguaje del acceso de Laravel (resources/views/auth): escena azul tinta con resplandor dorado y
 * turquesa, órbitas finas, tarjeta blanca de dos columnas, logo como marca de agua con halo y punto dorado, y el
 * formulario con iconos. Se mejora el contraste del texto y se respeta `prefers-reduced-motion`.
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
      className="relative isolate flex min-h-svh flex-col items-center justify-center gap-6 overflow-hidden bg-[linear-gradient(135deg,#071727,#0c2942_58%,#102f49)] px-3 py-6 text-panel-text antialiased sm:px-8 sm:py-10"
    >
      {/* Resplandores y órbitas de la escena (decorativos). */}
      <div aria-hidden="true" className="pointer-events-none absolute inset-0 -z-10 bg-[radial-gradient(ellipse_at_12%_8%,rgba(201,169,97,0.16),transparent_42%),radial-gradient(ellipse_at_88%_82%,rgba(33,126,145,0.26),transparent_48%)]" />
      <div aria-hidden="true" className="pointer-events-none fixed -left-[12vw] -top-[25vw] -z-10 aspect-square w-[min(54vw,680px)] rounded-full border border-oro-500/15" />
      <div aria-hidden="true" className="pointer-events-none fixed -bottom-[22vw] -right-[18vw] -z-10 aspect-square w-[min(54vw,680px)] rounded-full border border-cyan-200/10" />

      <div className="acceso-tarjeta relative z-[1] grid w-full max-w-[1000px] overflow-hidden rounded-3xl border border-oro-500/25 bg-white shadow-[0_35px_100px_rgba(0,0,0,0.42)] sm:max-w-[520px] lg:min-h-[620px] lg:max-w-[1000px] lg:grid-cols-[0.94fr_1.06fr] lg:rounded-[28px]">
        <aside className="relative hidden min-h-[620px] overflow-hidden bg-[radial-gradient(ellipse_at_95%_70%,rgba(35,143,151,0.24),transparent_62%),radial-gradient(ellipse_at_0%_0%,rgba(201,169,97,0.13),transparent_52%),linear-gradient(155deg,#153b52,#0a2540_58%,#071d30)] lg:block">
          {/* Marca de agua con halo (decorativa): el mismo recurso del acceso de Laravel. */}
          <div aria-hidden="true" className="pointer-events-none absolute inset-0 overflow-hidden">
            <span className="absolute left-[18%] top-[38%] block aspect-square w-[120%] -rotate-[25deg] rounded-full border border-oro-500/20 before:absolute before:inset-7 before:rounded-full before:border before:border-cyan-200/10 after:absolute after:left-[14%] after:top-[14%] after:size-1.5 after:rounded-full after:bg-oro-300 after:shadow-[0_0_20px_rgba(215,187,123,0.5)]" />
            <LogoHorus className="absolute -right-[24%] bottom-[3%] h-auto w-[115%] max-w-none -rotate-12 opacity-10 grayscale" />
          </div>

          <div className="relative flex min-h-[620px] flex-col justify-between p-9 text-white xl:p-11">
            <div className="flex w-fit items-center gap-3">
              <LogoHorus className="h-11 w-auto" prioridad />
              <div className="leading-tight">
                <p className="font-heading text-sm font-bold tracking-[0.18em]">HORUS GROUP</p>
                <p className="mt-1 text-xs text-white/70">Tecnología, educación, futuro</p>
              </div>
            </div>

            <div className="max-w-sm pb-3">
              <span aria-hidden="true" className="mb-6 block h-0.5 w-11 bg-gradient-to-r from-oro-300 to-oro-300/15" />
              <p className="text-sm font-medium text-oro-300">Panel de gestión académica</p>
              <p className="mt-4 font-heading text-4xl font-bold leading-[1.05] tracking-tight xl:text-5xl">
                Matrículas, pagos y asistencia en un solo lugar.
              </p>
              <p className="mt-5 max-w-xs text-sm leading-6 text-white/80">
                Registra cada pago Yape, matricula con su comprobante y sigue la asistencia de cada edición.
              </p>
            </div>

            <p className="flex items-center gap-2 text-xs font-medium text-white/75">
              <span aria-hidden="true" className="size-2 rounded-full bg-oro-300 shadow-[0_0_10px_rgba(215,187,123,0.7)]" />
              Cajamarca, Perú
              <span aria-hidden="true" className="h-px w-8 bg-white/30" />
              Horus Group SRL
            </p>
          </div>
        </aside>

        <section className="flex items-center px-5 py-8 sm:px-10 sm:py-10 lg:px-12">
          <div className="mx-auto w-full max-w-sm">
            <div className="mb-6 flex items-center gap-3 lg:hidden">
              <LogoHorus className="h-9 w-auto" />
              <p className="font-heading text-sm font-bold tracking-[0.16em] text-tinta">HORUS GROUP</p>
            </div>
            {children}
          </div>
        </section>
      </div>

      <p className="relative px-3 text-center text-xs text-[#a9bfcf]">© Horus Group SRL · Cajamarca, Perú</p>
    </MarcaPanel>
  );
}
