import { Search } from 'lucide-react';

/** Formulario simple (funciona sin JavaScript) que lleva a `/admin/academico/buscar?q=`. Los resultados los filtra Laravel por permiso. */
export function FormularioBusqueda({ valor }: { valor: string }) {
  return (
    <form action="/admin/academico/buscar" method="get" role="search" className="flex gap-2">
      <label htmlFor="q-buscar" className="sr-only">Buscar por DNI, nombre, código o número</label>
      <input
        id="q-buscar"
        name="q"
        type="search"
        defaultValue={valor}
        maxLength={100}
        autoComplete="off"
        placeholder="DNI, nombre, código de edición o número de matrícula"
        className="min-w-0 flex-1 rounded-lg border border-panel-border bg-panel-surface px-3 py-2.5 text-sm text-panel-text outline-offset-2 focus:border-academico-700 focus:outline-2 focus:outline-academico-700/40"
      />
      <button type="submit" className="inline-flex items-center gap-2 rounded-lg bg-academico-700 px-4 py-2.5 text-sm font-semibold text-white outline-offset-2 hover:bg-academico-800 focus-visible:outline-2 focus-visible:outline-academico-700">
        <Search className="h-4 w-4" aria-hidden="true" />
        Buscar
      </button>
    </form>
  );
}
