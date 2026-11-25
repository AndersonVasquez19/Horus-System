import Link from 'next/link';
import { ArrowDown, ArrowUp, ArrowUpDown } from 'lucide-react';
import type { ReactNode } from 'react';

/*
 * Piezas de presentación de listados y fichas del panel (Server Components). Sin datos ni lógica: cada página
 * aporta las filas. Comparten bordes, tipografía y espaciado para que todos los módulos se lean igual.
 */

export type ColumnaTabla =
  | string
  | { texto: string; oculta?: boolean; derecha?: boolean; orden?: string };

interface TablaProps {
  /** Descripción para lectores de pantalla (p. ej. «Estudiantes (42)»). */
  titulo: string;
  columnas: readonly ColumnaTabla[];
  children: ReactNode;
  /** Pie de la tabla (paginación). */
  pie?: ReactNode;
  /** Orden actual (`campo` o `-campo`) para cabeceras ordenables con `aria-sort`. */
  ordenActual?: string;
  /** Ruta base del listado para construir enlaces de ordenación en cabecera. */
  rutaOrden?: string;
  /** Parámetros de filtro actuales que deben conservarse al ordenar (sin `page`). */
  parametrosOrden?: Record<string, string>;
  /**
   * La última columna (el menú ⋯ de acciones) queda fija al borde derecho al desplazar la tabla en pantallas
   * pequeñas, para que nunca quede fuera de la vista.
   */
  accionesFijas?: boolean;
  /** Sin borde, sombra ni esquinas propias: para una tabla dentro de una `Seccion`, que ya es una tarjeta. */
  plana?: boolean;
}

/** Última columna pegada a la derecha. La sombra solo aparece en pantallas pequeñas, donde la tabla se desplaza. */
const ACCIONES_FIJAS = [
  '[&_thead_th:last-child]:sticky [&_thead_th:last-child]:right-0 [&_thead_th:last-child]:z-10 [&_thead_th:last-child]:bg-panel-bg',
  '[&_tbody_td:last-child]:sticky [&_tbody_td:last-child]:right-0 [&_tbody_td:last-child]:bg-panel-surface',
  '[&_tbody_tr:hover_td:last-child]:bg-[color-mix(in_srgb,var(--color-academico-50)_50%,var(--color-panel-surface))]',
  'max-md:[&_tbody_td:last-child]:shadow-[-8px_0_8px_-8px_rgba(15,23,42,0.18)]',
].join(' ');

function hrefOrden(ruta: string, parametros: Record<string, string>, sort: string): string {
  const qs = new URLSearchParams(parametros);
  qs.delete('page');
  qs.set('sort', sort);
  return `${ruta}?${qs.toString()}`;
}

export function Tabla({ titulo, columnas, children, pie, ordenActual, rutaOrden, parametrosOrden = {}, accionesFijas = false, plana = false }: TablaProps) {
  const campoActivo = ordenActual ? ordenActual.replace(/^-/, '') : '';
  const descActivo = ordenActual ? ordenActual.startsWith('-') : false;

  return (
    <div className={plana ? 'overflow-hidden bg-panel-surface' : 'overflow-hidden rounded-xl border border-panel-border bg-panel-surface shadow-sm'}>
      {/* Región con desplazamiento: debe poder enfocarse con el teclado para desplazarla (WCAG 2.1.1) y llevar nombre. */}
      <div className="overflow-x-auto focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-academico-700" tabIndex={0} role="region" aria-label={titulo}>
        {/* `min-w` evita que las celdas se aplasten en móvil: la tabla se desplaza en vez de partir cada palabra. */}
        <table className={`w-full min-w-[40rem] text-left text-sm ${accionesFijas ? ACCIONES_FIJAS : ''}`}>
          <caption className="sr-only">{titulo}</caption>
          <thead className="border-b border-panel-border bg-panel-bg text-sm font-semibold text-panel-muted">
            <tr>
              {columnas.map((columna) => {
                const { texto, oculta = false, derecha = false, orden } = typeof columna === 'string' ? { texto: columna } : columna;
                const ordenable = orden !== undefined && rutaOrden !== undefined && !oculta;
                const esActiva = ordenable && campoActivo === orden;
                const ariaSort = ordenable ? (esActiva ? (descActivo ? 'descending' : 'ascending') : 'none') : undefined;
                const siguiente = orden ? (esActiva && !descActivo ? `-${orden}` : orden) : '';
                const IconoOrden = !esActiva ? ArrowUpDown : descActivo ? ArrowDown : ArrowUp;

                return (
                  <th key={texto} scope="col" aria-sort={ariaSort} className={`px-4 py-3 font-semibold ${derecha ? 'text-right' : ''}`}>
                    {oculta ? (
                      <span className="sr-only">{texto}</span>
                    ) : ordenable && rutaOrden ? (
                      <Link
                        href={hrefOrden(rutaOrden, parametrosOrden, siguiente)}
                        className={`inline-flex items-center gap-1 rounded outline-offset-2 hover:text-panel-text focus-visible:outline-2 focus-visible:outline-academico-700 ${derecha ? 'justify-end' : ''}`}
                      >
                        <span>{texto}</span>
                        <IconoOrden className="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                      </Link>
                    ) : (
                      texto
                    )}
                  </th>
                );
              })}
            </tr>
          </thead>
          <tbody className="divide-y divide-panel-border">{children}</tbody>
        </table>
      </div>
      {pie}
    </div>
  );
}

export function EsqueletoListado({ etiqueta, filas = 6 }: { etiqueta: string; filas?: number }) {
  return (
    <div className="space-y-4" aria-busy="true" aria-label={etiqueta}>
      {/* Altura parecida a la de la barra de filtros real (en móvil queda plegada; en escritorio ocupa una o dos filas). */}
      <div className="h-16 animate-pulse rounded-xl border border-panel-border bg-panel-surface md:h-24" />
      <div className="space-y-px overflow-hidden rounded-xl border border-panel-border bg-panel-surface">
        {Array.from({ length: filas }, (_, n) => (
          <div key={n} className="h-14 animate-pulse bg-panel-surface odd:bg-panel-bg" />
        ))}
      </div>
    </div>
  );
}

export function EsqueletoFicha({ etiqueta }: { etiqueta: string }) {
  return (
    <div className="grid gap-6 xl:grid-cols-3" aria-busy="true" aria-label={etiqueta}>
      <div className="h-80 animate-pulse rounded-xl border border-panel-border bg-panel-surface" />
      <div className="h-80 animate-pulse rounded-xl border border-panel-border bg-panel-surface xl:col-span-2" />
    </div>
  );
}

/** Tarjeta con título (h2) para las secciones de una ficha. */
export function Seccion({ id, titulo, descripcion, acciones, children, className = '', sinRelleno = false }: {
  id: string;
  titulo: string;
  descripcion?: string;
  acciones?: ReactNode;
  children: ReactNode;
  className?: string;
  sinRelleno?: boolean;
}) {
  return (
    <section aria-labelledby={id} className={`overflow-hidden rounded-xl border border-panel-border bg-panel-surface shadow-sm ${className}`}>
      <div className="flex flex-wrap items-start justify-between gap-3 px-5 pt-5">
        <div>
          <h2 id={id} className="font-heading text-base font-semibold text-panel-text">{titulo}</h2>
          {descripcion ? <p className="text-xs text-panel-muted">{descripcion}</p> : null}
        </div>
        {acciones}
      </div>
      <div className={sinRelleno ? 'mt-3' : 'p-5 pt-4'}>{children}</div>
    </section>
  );
}

/** Lista de término y valor de una ficha. */
export function ListaDatos({ filas }: { filas: readonly (readonly [string, ReactNode])[] }) {
  return (
    <dl className="space-y-3 text-sm">
      {filas.map(([termino, definicion]) => (
        <div key={termino} className="grid grid-cols-1 gap-0.5 sm:grid-cols-3 sm:gap-2">
          <dt className="text-panel-muted">{termino}</dt>
          <dd className="break-words font-medium text-panel-text sm:col-span-2">{definicion}</dd>
        </div>
      ))}
    </dl>
  );
}
