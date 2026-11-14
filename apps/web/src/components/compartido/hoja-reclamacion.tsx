'use client';

import { Printer } from 'lucide-react';
import { Boton, type TonoUi } from '@/components/shared/ui/boton';
import type { HojaReclamacionDatos } from '@/features/notificaciones/tipos';

/**
 * Hoja de reclamación imprimible. El texto oficial lo compone Laravel (el mismo que se guarda como copia al
 * consumidor); aquí solo se muestra con escape normal de React y se imprime en A4. Al imprimir se oculta todo salvo
 * la hoja, y desde la segunda página el número de expediente se repite en el margen superior.
 */
export function HojaReclamacion({ hoja, tono }: { hoja: HojaReclamacionDatos; tono: TonoUi }) {
  // Solo dígitos, letras y guion: se inserta dentro de una cadena CSS.
  const numeroCss = hoja.numero.replace(/[^0-9A-Za-z-]/g, '');

  return (
    <>
      <style>{`
        @page { size: A4; margin: 16mm 14mm; @top-right { content: "Hoja de Reclamación N.º ${numeroCss}"; font: 600 9pt sans-serif; } @bottom-right { content: "Página " counter(page) " de " counter(pages); font: 8pt sans-serif; } }
        @page :first { @top-right { content: none; } }
        @media print {
          body * { visibility: hidden; }
          .hoja-imprimible, .hoja-imprimible * { visibility: visible; }
          .hoja-imprimible { position: absolute; left: 0; top: 0; width: 100%; border: 0; box-shadow: none; }
          .no-imprimir { display: none !important; }
        }
      `}</style>

      <div className="no-imprimir mb-4 flex justify-end">
        <Boton tono={tono} icono={<Printer className="h-4 w-4" aria-hidden="true" />} onClick={() => window.print()}>Imprimir</Boton>
      </div>

      <article className="hoja-imprimible mx-auto max-w-3xl rounded-xl border border-panel-border bg-white text-slate-900 shadow-sm">
        <header className="rounded-t-xl border-b-4 border-teal-500 bg-slate-900 px-7 py-5 text-white">
          <h2 className="text-xl font-semibold">Hoja de Reclamación N.º {hoja.numero}</h2>
          <p className="mt-1 text-sm text-slate-300">{hoja.tipo === 'reclamo' ? 'Reclamo' : 'Queja'} · {hoja.canal_etiqueta}</p>
        </header>
        <div className="space-y-6 px-7 py-6">
          <pre className="whitespace-pre-wrap break-words font-sans text-sm" data-hoja-oficial>{hoja.texto_oficial}</pre>
          <section>
            <h3 className="mb-2 border-b border-slate-200 pb-1 text-xs font-semibold uppercase tracking-wide text-sky-800">4. Observaciones y acciones adoptadas por el proveedor</h3>
            {hoja.respuesta ? (
              <>
                <p className="mb-1 text-xs text-slate-500">Fecha de comunicación de la respuesta: {hoja.respondida_local ?? '—'} (Lima)</p>
                <pre className="whitespace-pre-wrap break-words font-sans text-sm">{hoja.respuesta}</pre>
              </>
            ) : (
              <p className="text-sm">Pendiente de respuesta.</p>
            )}
          </section>
        </div>
        <footer className="border-t border-slate-200 px-7 py-3 text-[11px] text-slate-500">
          Impreso desde el panel administrativo el {hoja.impresa_local} (Lima). Documento con datos personales: uso exclusivo para la atención de la reclamación.
        </footer>
      </article>
    </>
  );
}
