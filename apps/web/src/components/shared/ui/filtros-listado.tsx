import Form from 'next/form';
import Link from 'next/link';
import { Search } from 'lucide-react';
import type { ReactNode } from 'react';
import type { TonoUi } from './boton';

/*
 * Barra de filtros de los listados del panel: formulario GET (funciona sin JavaScript; con él, `next/form`
 * navega en el cliente). Los valores llegan a Laravel, que aplica su lista blanca.
 */

const ESTILOS: Record<TonoUi, { control: string; boton: string }> = {
  academico: {
    control: 'focus:border-academico-700 focus:ring-academico-700/30',
    boton: 'bg-academico-700 hover:bg-academico-800 focus-visible:outline-academico-700',
  },
};

export function claseControl(tono: TonoUi): string {
  return `rounded-lg border border-panel-border bg-panel-surface px-3 py-2 text-sm text-panel-text focus:outline-none focus:ring-4 ${ESTILOS[tono].control}`;
}

interface FiltrosListadoProps {
  tono: TonoUi;
  ruta: string;
  hayFiltros: boolean;
  children: ReactNode;
}

export function FiltrosListado({ tono, ruta, hayFiltros, children }: FiltrosListadoProps) {
  return (
    <Form action={ruta} className="flex flex-wrap items-end gap-3 rounded-xl border border-panel-border bg-panel-surface p-4 shadow-sm">
      {children}
      <div className="flex gap-2">
        <button type="submit" className={`rounded-lg px-4 py-2 text-sm font-semibold text-white outline-offset-2 focus-visible:outline-2 ${ESTILOS[tono].boton}`}>
          Aplicar
        </button>
        {hayFiltros ? (
          <Link href={ruta} className="rounded-lg px-3 py-2 text-sm font-medium text-panel-muted hover:bg-panel-bg hover:text-panel-text">
            Limpiar
          </Link>
        ) : null}
      </div>
    </Form>
  );
}

export function CampoBusqueda({ tono, nombre, valor, marcador, etiqueta = 'Buscar' }: { tono: TonoUi; nombre: string; valor: string; marcador: string; etiqueta?: string }) {
  return (
    <div className="min-w-56 flex-1">
      <label htmlFor={nombre} className="mb-1 block text-xs font-medium text-panel-muted">{etiqueta}</label>
      <div className="relative">
        <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-panel-muted" aria-hidden="true" />
        <input id={nombre} name={nombre} type="search" defaultValue={valor} placeholder={marcador} maxLength={200} className={`${claseControl(tono)} w-full pl-9`} />
      </div>
    </div>
  );
}

export function SelectorFiltro({ tono, nombre, etiqueta, valor, opciones, todos = 'Todos' }: { tono: TonoUi; nombre: string; etiqueta: string; valor: string; opciones: Record<string, string>; todos?: string | null }) {
  return (
    <div>
      <label htmlFor={nombre} className="mb-1 block text-xs font-medium text-panel-muted">{etiqueta}</label>
      <select id={nombre} name={nombre} defaultValue={valor} className={claseControl(tono)}>
        {todos !== null ? <option value="">{todos}</option> : null}
        {Object.entries(opciones).map(([clave, texto]) => (
          <option key={clave} value={clave}>{texto}</option>
        ))}
      </select>
    </div>
  );
}

export function CampoFecha({ tono, nombre, etiqueta, valor }: { tono: TonoUi; nombre: string; etiqueta: string; valor: string }) {
  return (
    <div>
      <label htmlFor={nombre} className="mb-1 block text-xs font-medium text-panel-muted">{etiqueta}</label>
      <input id={nombre} name={nombre} type="date" defaultValue={valor} className={claseControl(tono)} />
    </div>
  );
}

/** Primer valor de un parámetro de búsqueda. */
export function parametro(parametros: Record<string, string | string[] | undefined>, nombre: string): string {
  const dato = parametros[nombre];
  return (Array.isArray(dato) ? dato[0] : dato) ?? '';
}
