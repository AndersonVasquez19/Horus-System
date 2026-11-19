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
}

function hrefOrden(ruta: string, parametros: Record<string, string>, sort: string): string {
  const qs = new URLSearchParams(parametros);
  qs.delete('page');
  qs.set('sort', sort);
  return `${ruta}?${qs.toString()}`;
}

export function Tabla({ titulo, columnas, children, pie, ordenActual, rutaOrden, parametrosOrden = {} }: TablaProps) {
  const campoActivo = ordenActual ? ordenActual.replace(/^-/, '') : '';
  const descActivo = ordenActual ? ordenActual.startsWith('-') : false;

  return (
    <div className="overflow-hidden rounded-xl border border-panel-border bg-panel-surface shadow-sm">
      <div className="overflow-x-auto">
        <table className="w-full text-left text-sm">
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
      <div className="h-20 animate-pulse rounded-xl border border-panel-border bg-panel-surface" />
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
        <div key={termino} className="grid grid-cols-3 gap-2">
          <dt className="text-panel-muted">{termino}</dt>
          <dd className="col-span-2 break-words font-medium text-panel-text">{definicion}</dd>
        </div>
      ))}
    </dl>
  );
}
