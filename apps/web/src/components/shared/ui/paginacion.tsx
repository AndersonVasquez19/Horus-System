import Link from 'next/link';
import { ChevronLeft, ChevronRight } from 'lucide-react';

export interface MetaListado {
  pagina: number;
  por_pagina: number;
  total: number;
  paginas: number;
  orden: string;
  filtros: Record<string, string | boolean>;
}

interface PaginacionProps {
  meta: MetaListado;
  ruta: string;
  /** Parámetros actuales que deben conservarse al cambiar de página. */
  parametros: Record<string, string>;
  /** Fuera de una tarjeta (bajo una lista de tarjetas): sin el borde superior, que quedaría como una línea suelta. */
  suelta?: boolean;
  /** Cómo se llama cada tramo: «Página» (por omisión) o «Lote» en los reportes, que se pagina por lotes. */
  unidad?: 'Página' | 'Lote';
}

function href(ruta: string, parametros: Record<string, string>, pagina: number): string {
  const busqueda = new URLSearchParams(parametros);
  if (pagina > 1) busqueda.set('page', String(pagina));
  else busqueda.delete('page');
  const texto = busqueda.toString();
  return texto ? `${ruta}?${texto}` : ruta;
}

export function Paginacion({ meta, ruta, parametros, suelta = false, unidad = 'Página' }: PaginacionProps) {
  const desde = meta.total === 0 ? 0 : (meta.pagina - 1) * meta.por_pagina + 1;
  const hasta = Math.min(meta.pagina * meta.por_pagina, meta.total);
  const clase = 'inline-flex items-center gap-1 rounded-lg border border-panel-border bg-panel-surface min-h-10 px-3 py-2 text-sm font-medium text-panel-text';

  return (
    <nav aria-label="Paginación" className={`flex flex-wrap items-center justify-between gap-3 print:hidden ${suelta ? 'px-1 py-1' : 'border-t border-panel-border px-4 py-3'}`}>
      <p className="text-sm text-panel-muted">
        {meta.total === 0 ? 'Sin resultados' : `${desde}–${hasta} de ${meta.total}`}
      </p>
      <div className="flex items-center gap-2">
        {meta.pagina > 1 ? (
          <Link href={href(ruta, parametros, meta.pagina - 1)} className={`${clase} hover:bg-panel-bg`} rel="prev">
            <ChevronLeft className="h-4 w-4" aria-hidden="true" /> Anterior
          </Link>
        ) : (
          <span className={`${clase} opacity-50`} aria-disabled="true">
            <ChevronLeft className="h-4 w-4" aria-hidden="true" /> Anterior
          </span>
        )}
        <span className="text-sm text-panel-muted">
          {unidad} {meta.pagina} de {Math.max(1, meta.paginas)}
        </span>
        {meta.pagina < meta.paginas ? (
          <Link href={href(ruta, parametros, meta.pagina + 1)} className={`${clase} hover:bg-panel-bg`} rel="next">
            Siguiente <ChevronRight className="h-4 w-4" aria-hidden="true" />
          </Link>
        ) : (
          <span className={`${clase} opacity-50`} aria-disabled="true">
            Siguiente <ChevronRight className="h-4 w-4" aria-hidden="true" />
          </span>
        )}
      </div>
    </nav>
  );
}
