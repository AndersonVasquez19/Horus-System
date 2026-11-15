import { Suspense } from 'react';
import Link from 'next/link';
import { BotonNuevoProyecto } from '@/components/servicios/acciones-proyecto';
import { EncabezadoPagina } from '@/components/shared/ui/encabezado-pagina';
import { EstadoError, EstadoProhibido, EstadoSinResultados, EstadoVacio } from '@/components/shared/ui/estados';
import { CampoBusqueda, FiltrosListado, SelectorFiltro, parametro } from '@/components/shared/ui/filtros-listado';
import { Insignia, colorEstado } from '@/components/shared/ui/insignia';
import { Paginacion, type MetaListado } from '@/components/shared/ui/paginacion';
import type { ProyectoResumen } from '@/features/servicios/tipos';
import { consulta, consultarPanel } from '@/lib/panel/api-servidor';
import { ETIQUETAS, etiqueta, fecha } from '@/lib/panel/formato';

export const metadata = { title: 'Proyectos · Servicios tecnológicos' };

const RUTA = '/admin/servicios/proyectos';
const PARAMETROS = ['busqueda', 'estado', 'activos', 'sort', 'page'] as const;
type Parametros = Record<string, string | string[] | undefined>;

export default function ProyectosPage({ searchParams }: { searchParams: Promise<Parametros> }) {
  return (
    <>
      <EncabezadoPagina
        titulo="Proyectos"
        descripcion="Trabajos para clientes, con sus hitos y su avance. Un proyecto puede nacer de una oportunidad ganada."
        migas={[{ etiqueta: 'Inicio', href: '/admin/servicios' }, { etiqueta: 'Proyectos' }]}
        acciones={<BotonNuevoProyecto />}
      />
      <Suspense fallback={<div className="h-96 animate-pulse rounded-xl border border-panel-border bg-panel-surface" aria-busy="true" aria-label="Cargando proyectos" />}>
        <Listado searchParams={searchParams} />
      </Suspense>
    </>
  );
}

async function Listado({ searchParams }: { searchParams: Promise<Parametros> }) {
  const parametros = await searchParams;
  const cadena = consulta(parametros, PARAMETROS);
  const resultado = await consultarPanel<{ data: ProyectoResumen[]; meta: MetaListado }>(`servicios/proyectos${cadena}`, `${RUTA}${cadena}`);
  if (resultado.estado === 'prohibido') return <EstadoProhibido volver="/admin/servicios" />;
  if (resultado.estado !== 'ok') return <EstadoError texto="Inténtalo de nuevo en unos minutos." />;

  const { data: proyectos, meta } = resultado.datos;
  const hayFiltros = Object.keys(meta.filtros).length > 0;
  const actuales = Object.fromEntries(PARAMETROS.filter((n) => n !== 'page' && parametro(parametros, n)).map((n) => [n, parametro(parametros, n)]));

  return (
    <div className="space-y-4">
      <FiltrosListado tono="servicios" ruta={RUTA} hayFiltros={hayFiltros}>
        <CampoBusqueda tono="servicios" nombre="busqueda" valor={parametro(parametros, 'busqueda')} marcador="Nombre o código" />
        <SelectorFiltro tono="servicios" nombre="estado" etiqueta="Estado" valor={parametro(parametros, 'estado')} opciones={ETIQUETAS.estadoProyecto} />
        <label className="flex items-center gap-2 pb-2 text-sm text-panel-text">
          <input type="checkbox" name="activos" value="1" defaultChecked={parametro(parametros, 'activos') === '1'} className="h-4 w-4 accent-servicios-700" />
          Solo activos
        </label>
      </FiltrosListado>

      {proyectos.length === 0 ? (
        hayFiltros ? <EstadoSinResultados titulo="Ningún proyecto coincide con los filtros" /> : <EstadoVacio titulo="Todavía no hay proyectos registrados" />
      ) : (
        <div className="overflow-hidden rounded-xl border border-panel-border bg-panel-surface shadow-sm">
          <div className="overflow-x-auto">
            <table className="w-full text-left text-sm">
              <caption className="sr-only">Proyectos ({meta.total})</caption>
              <thead className="border-b border-panel-border bg-panel-bg text-xs uppercase tracking-wide text-panel-muted">
                <tr>
                  <th scope="col" className="px-4 py-3 font-semibold">Proyecto</th>
                  <th scope="col" className="px-4 py-3 font-semibold">Estado</th>
                  <th scope="col" className="px-4 py-3 font-semibold">Avance</th>
                  <th scope="col" className="px-4 py-3 font-semibold">Responsable</th>
                  <th scope="col" className="px-4 py-3 font-semibold">Fin estimado</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-panel-border">
                {proyectos.map((p) => (
                  <tr key={p.identificador} className="hover:bg-servicios-50/50">
                    <td className="px-4 py-3">
                      <Link href={`${RUTA}/${p.identificador}`} className="font-semibold text-panel-text hover:text-servicios-800 hover:underline">{p.nombre}</Link>
                      <p className="text-xs text-panel-muted">{p.codigo}{p.cliente ? ` · ${p.cliente.nombre}` : ''}</p>
                    </td>
                    <td className="px-4 py-3"><Insignia color={colorEstado(p.estado)}>{etiqueta('estadoProyecto', p.estado)}</Insignia></td>
                    <td className="px-4 py-3 text-panel-muted">{p.hitos.avance === null ? 'Sin hitos' : `${p.hitos.avance} % (${p.hitos.completados}/${p.hitos.total})`}</td>
                    <td className="px-4 py-3 text-panel-muted">{p.responsable ?? 'Sin asignar'}</td>
                    <td className="whitespace-nowrap px-4 py-3 text-panel-muted">{fecha(p.fecha_fin_estimada)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          <Paginacion meta={meta} ruta={RUTA} parametros={actuales} />
        </div>
      )}
    </div>
  );
}
