'use client';

import { ChevronDown, SlidersHorizontal } from 'lucide-react';
import { useId, useState, type ReactNode } from 'react';

/**
 * Envoltorio de los controles de `FiltrosListado`. En pantallas anchas es transparente (`display: contents`): los
 * controles se reparten en la fila del formulario como siempre. En móvil se pliega detrás de un botón «Filtros» para
 * no ocupar media pantalla antes de ver el listado. Plegados siguen dentro del `<form>`, así que se envían igual.
 */
export function ContenedorFiltros({ activos, children }: { activos: boolean; children: ReactNode }) {
  const [abierto, setAbierto] = useState(false);
  const id = useId();

  return (
    <>
      <button
        type="button"
        onClick={() => setAbierto((v) => !v)}
        aria-expanded={abierto}
        aria-controls={id}
        className="flex min-h-10 w-full items-center justify-between rounded-lg border border-panel-border bg-panel-bg px-3 text-sm font-semibold text-panel-text md:hidden"
      >
        <span className="inline-flex items-center gap-2">
          <SlidersHorizontal className="h-4 w-4 text-panel-muted" aria-hidden="true" />
          Filtros
          {activos ? <span className="rounded-full bg-academico-700 px-2 py-0.5 text-xs font-medium text-white">activos</span> : null}
        </span>
        <ChevronDown className={`h-4 w-4 text-panel-muted transition-transform ${abierto ? 'rotate-180' : ''}`} aria-hidden="true" />
      </button>
      <div id={id} className={`${abierto ? 'flex' : 'hidden'} w-full flex-wrap items-end gap-3 md:contents`}>
        {children}
      </div>
    </>
  );
}
