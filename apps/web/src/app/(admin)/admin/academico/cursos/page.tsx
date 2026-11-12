import Form from 'next/form';
import Link from 'next/link';
import { Search } from 'lucide-react';
import { Suspense } from 'react';
import { BotonNuevoCurso } from '@/components/academico/acciones-curso';
import { EncabezadoPagina } from '@/components/shared/ui/encabezado-pagina';
import { EstadoError, EstadoProhibido, EstadoSinResultados, EstadoVacio } from '@/components/shared/ui/estados';
import { Insignia, colorEstado } from '@/components/shared/ui/insignia';
import { Paginacion, type MetaListado } from '@/components/shared/ui/paginacion';
import type { CursoResumen } from '@/features/academico/tipos';
import { consulta, consultarPanel } from '@/lib/panel/api-servidor';
import { ETIQUETAS, etiqueta } from '@/lib/panel/formato';

export const metadata = { title: 'Cursos · Gestión académica' };

const RUTA = '/admin/academico/cursos';
const PARAMETROS = ['busqueda', 'estado', 'modalidad', 'categoria', 'eliminados', 'sort', 'page'] as const;

type Parametros = Record<string, string | string[] | undefined>;

export default function CursosPage({ searchParams }: { searchParams: Promise<Parametros> }) {
  return (
    <>
      <EncabezadoPagina
        titulo="Cursos"
        descripcion="Oferta académica, cupos y estado de cada curso."
        migas={[{ etiqueta: 'Inicio', href: '/admin/academico' }, { etiqueta: 'Cursos' }]}
        acciones={<BotonNuevoCurso />}
      />
      <Suspense fallback={<EsqueletoListado />}>
        <ListadoCursos searchParams={searchParams} />
      </Suspense>
    </>
  );
}

function valor(parametros: Parametros, nombre: string): string {
  const dato = parametros[nombre];
  return (Array.isArray(dato) ? dato[0] : dato) ?? '';
}

async function ListadoCursos({ searchParams }: { searchParams: Promise<Parametros> }) {
  const parametros = await searchParams;
  const cadena = consulta(parametros, PARAMETROS);
  const resultado = await consultarPanel<{ data: CursoResumen[]; meta: MetaListado }>(`academico/cursos${cadena}`, `${RUTA}${cadena}`);

  if (resultado.estado === 'prohibido') return <EstadoProhibido volver="/admin/academico" />;
  if (resultado.estado !== 'ok') return <EstadoError texto="Inténtalo de nuevo en unos minutos." />;

  const { data: cursos, meta } = resultado.datos;
  const actuales = Object.fromEntries(
    PARAMETROS.filter((nombre) => nombre !== 'page' && valor(parametros, nombre) !== '').map((nombre) => [nombre, valor(parametros, nombre)]),
  );
  const hayFiltros = Object.keys(meta.filtros).length > 0;
  const selector = 'rounded-lg border border-panel-border bg-panel-surface px-3 py-2 text-sm text-panel-text focus:border-academico-700 focus:outline-none focus:ring-4 focus:ring-academico-700/30';

  return (
    <div className="space-y-4">
      <Form action={RUTA} className="flex flex-wrap items-end gap-3 rounded-xl border border-panel-border bg-panel-surface p-4 shadow-sm">
        <div className="min-w-56 flex-1">
          <label htmlFor="busqueda" className="mb-1 block text-xs font-medium text-panel-muted">Buscar</label>
          <div className="relative">
            <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-panel-muted" aria-hidden="true" />
            <input
              id="busqueda"
              name="busqueda"
              type="search"
              defaultValue={valor(parametros, 'busqueda')}
              placeholder="Código o nombre"
              maxLength={200}
              className={`${selector} w-full pl-9`}
            />
          </div>
        </div>
        <Selector id="estado" etiqueta="Estado" valor={valor(parametros, 'estado')} opciones={ETIQUETAS.estadoCurso} clase={selector} />
        <Selector id="modalidad" etiqueta="Modalidad" valor={valor(parametros, 'modalidad')} opciones={ETIQUETAS.modalidad} clase={selector} />
        <Selector id="categoria" etiqueta="Categoría" valor={valor(parametros, 'categoria')} opciones={ETIQUETAS.categoria} clase={selector} />
        <div>
          <label htmlFor="sort" className="mb-1 block text-xs font-medium text-panel-muted">Ordenar por</label>
          <select id="sort" name="sort" defaultValue={valor(parametros, 'sort') || 'nombre'} className={selector}>
            <option value="nombre">Nombre (A–Z)</option>
            <option value="-nombre">Nombre (Z–A)</option>
            <option value="codigo">Código</option>
            <option value="cupos_disponibles">Menos cupos disponibles</option>
            <option value="-creado">Más recientes</option>
          </select>
        </div>
        <label className="flex items-center gap-2 pb-2 text-sm text-panel-text">
          <input type="checkbox" name="eliminados" value="1" defaultChecked={valor(parametros, 'eliminados') === '1'} className="h-4 w-4 rounded border-panel-border accent-academico-700" />
          Archivados
        </label>
        <div className="flex gap-2">
          <button type="submit" className="rounded-lg bg-academico-700 px-4 py-2 text-sm font-semibold text-white outline-offset-2 hover:bg-academico-800 focus-visible:outline-2 focus-visible:outline-academico-700">
            Aplicar
          </button>
          {hayFiltros ? (
            <Link href={RUTA} className="rounded-lg px-3 py-2 text-sm font-medium text-panel-muted hover:bg-panel-bg hover:text-panel-text">
              Limpiar
            </Link>
          ) : null}
        </div>
      </Form>

      {cursos.length === 0 ? (
        hayFiltros ? (
          <EstadoSinResultados titulo="Ningún curso coincide con los filtros" texto="Prueba con otros criterios o limpia los filtros." />
        ) : (
          <EstadoVacio titulo="Todavía no hay cursos registrados" texto="Registra el primero con «Nuevo curso»." />
        )
      ) : (
        <div className="overflow-hidden rounded-xl border border-panel-border bg-panel-surface shadow-sm">
          <div className="overflow-x-auto">
            <table className="w-full text-left text-sm">
              <caption className="sr-only">Cursos ({meta.total})</caption>
              <thead className="border-b border-panel-border bg-panel-bg text-xs uppercase tracking-wide text-panel-muted">
                <tr>
                  <th scope="col" className="px-4 py-3 font-semibold">Curso</th>
                  <th scope="col" className="px-4 py-3 font-semibold">Categoría</th>
                  <th scope="col" className="px-4 py-3 font-semibold">Modalidad</th>
                  <th scope="col" className="px-4 py-3 font-semibold">Cupos</th>
                  <th scope="col" className="px-4 py-3 font-semibold">Estado</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-panel-border">
                {cursos.map((curso) => {
                  const ocupados = curso.cupos_maximos - curso.cupos_disponibles;
                  const porcentaje = curso.cupos_maximos > 0 ? Math.round((ocupados / curso.cupos_maximos) * 100) : 0;
                  return (
                    <tr key={curso.id} className="hover:bg-academico-50/50">
                      <td className="px-4 py-3">
                        <Link href={`${RUTA}/${curso.id}`} className="font-semibold text-panel-text hover:text-academico-800 hover:underline">
                          {curso.nombre}
                        </Link>
                        <p className="text-xs text-panel-muted">{curso.codigo}</p>
                      </td>
                      <td className="px-4 py-3 text-panel-muted">{etiqueta('categoria', curso.categoria)}</td>
                      <td className="px-4 py-3 text-panel-muted">{etiqueta('modalidad', curso.modalidad)}</td>
                      <td className="px-4 py-3">
                        <div className="flex items-center gap-2">
                          <div className="h-1.5 w-20 overflow-hidden rounded-full bg-panel-border" aria-hidden="true">
                            <div className="h-full rounded-full bg-academico-600" style={{ width: `${porcentaje}%` }} />
                          </div>
                          <span className="whitespace-nowrap text-xs text-panel-muted">
                            {curso.cupos_disponibles} libres de {curso.cupos_maximos}
                          </span>
                        </div>
                      </td>
                      <td className="px-4 py-3">
                        <div className="flex flex-wrap gap-1">
                          <Insignia color={colorEstado(curso.estado)}>{etiqueta('estadoCurso', curso.estado)}</Insignia>
                          {curso.eliminado ? <Insignia color="rojo">Archivado</Insignia> : null}
                        </div>
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
          <Paginacion meta={meta} ruta={RUTA} parametros={actuales} />
        </div>
      )}
    </div>
  );
}

function Selector({ id, etiqueta: texto, valor: actual, opciones, clase }: { id: string; etiqueta: string; valor: string; opciones: Record<string, string>; clase: string }) {
  return (
    <div>
      <label htmlFor={id} className="mb-1 block text-xs font-medium text-panel-muted">{texto}</label>
      <select id={id} name={id} defaultValue={actual} className={clase}>
        <option value="">Todos</option>
        {Object.entries(opciones).map(([clave, nombre]) => (
          <option key={clave} value={clave}>{nombre}</option>
        ))}
      </select>
    </div>
  );
}

function EsqueletoListado() {
  return (
    <div className="space-y-4" aria-busy="true" aria-label="Cargando cursos">
      <div className="h-20 animate-pulse rounded-xl border border-panel-border bg-panel-surface" />
      <div className="space-y-px overflow-hidden rounded-xl border border-panel-border bg-panel-surface">
        {[0, 1, 2, 3, 4, 5].map((n) => (
          <div key={n} className="h-14 animate-pulse bg-panel-surface odd:bg-panel-bg" />
        ))}
      </div>
    </div>
  );
}
