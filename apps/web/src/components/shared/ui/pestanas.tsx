'use client';

import { useState, type ReactNode } from 'react';

export interface ElementoPestana {
  id: string;
  etiqueta: string;
  insignia?: number | string;
  contenido: ReactNode;
}

interface PestanasProps {
  elementos: ElementoPestana[];
  inicial?: string;
  etiquetaAria?: string;
}

/**
 * Pestañas limpias y accesibles para dividir pantallas de detalle extensas (p. ej. Ediciones)
 * sin obligar a recorrer una sábana vertical de módulos.
 */
export function Pestanas({ elementos, inicial, etiquetaAria = 'Secciones' }: PestanasProps) {
  const validos = elementos.filter(Boolean);
  const [activa, setActiva] = useState<string>(inicial ?? validos[0]?.id ?? '');
  const actual = validos.find((e) => e.id === activa) ?? validos[0];

  if (!actual) return null;

  return (
    <div className="space-y-5">
      <div
        role="tablist"
        aria-label={etiquetaAria}
        className="flex flex-wrap gap-1.5 border-b border-panel-border pb-2 print:hidden"
      >
        {validos.map((el) => {
          const seleccionada = el.id === actual.id;
          return (
            <button
              key={el.id}
              type="button"
              role="tab"
              aria-selected={seleccionada}
              aria-controls={`panel-tab-${el.id}`}
              id={`tab-${el.id}`}
              onClick={() => setActiva(el.id)}
              className={`inline-flex items-center gap-2 rounded-lg px-3.5 py-2 text-sm font-semibold transition cursor-pointer ${
                seleccionada
                  ? 'bg-academico-700 text-white shadow-sm'
                  : 'text-panel-muted hover:bg-panel-bg hover:text-panel-text'
              }`}
            >
              <span>{el.etiqueta}</span>
              {el.insignia !== undefined ? (
                <span
                  className={`inline-flex min-w-5 items-center justify-center rounded-full px-1.5 py-0.5 text-[11px] tabular-nums ${
                    seleccionada ? 'bg-white/20 text-white' : 'bg-panel-bg text-panel-muted'
                  }`}
                >
                  {el.insignia}
                </span>
              ) : null}
            </button>
          );
        })}
      </div>

      <div role="tabpanel" id={`panel-tab-${actual.id}`} aria-labelledby={`tab-${actual.id}`}>
        {actual.contenido}
      </div>
    </div>
  );
}
