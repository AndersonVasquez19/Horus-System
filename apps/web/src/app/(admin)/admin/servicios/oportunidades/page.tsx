import { Suspense } from 'react';
import Link from 'next/link';
import { BotonNuevaOportunidad, TableroOportunidades } from '@/components/servicios/acciones-oportunidad';
import { EncabezadoPagina } from '@/components/shared/ui/encabezado-pagina';
import { EstadoError, EstadoProhibido, EstadoSinResultados, EstadoVacio } from '@/components/shared/ui/estados';
import { CampoBusqueda, FiltrosListado, SelectorFiltro, parametro } from '@/components/shared/ui/filtros-listado';
import { Insignia, colorEstado } from '@/components/shared/ui/insignia';
import { Paginacion, type MetaListado } from '@/components/shared/ui/paginacion';
import type { ColumnaTablero, OportunidadResumen } from '@/features/servicios/tipos';
import { consulta, consultarPanel } from '@/lib/panel/api-servidor';
import { ETIQUETAS, etiqueta, fecha, moneda } from '@/lib/panel/formato';

export const metadata = { title: 'Pipeline · Servicios tecnológicos' };

const RUTA = '/admin/servicios/oportunidades';
const PARAMETROS = ['busqueda', 'etapa', 'abiertas', 'sort', 'page'] as const;
type Parametros = Record<string, string | string[] | undefined>;

export default function OportunidadesPage({ searchParams }: { searchParams: Promise<Parametros> }) {
  return (
    <>
      <EncabezadoPagina
        titulo="Pipeline"
        descripcion="Oportunidades comerciales por etapa. Mueve una tarjeta con su selector o arrastrándola; la pérdida pide un motivo."
        migas={[{ etiqueta: 'Inicio', href: '/admin/servicios' }, { etiqueta: 'Pipeline' }]}
        acciones={<BotonNuevaOportunidad />}
      />
      <Suspense fallback={<div className="h-96 animate-pulse rounded-xl border border-panel-border bg-panel-surface" aria-busy="true" aria-label="Cargando el pipeline" />}>
        <Contenido searchParams={searchParams} />
      </Suspense>
    </>
  );
}

async function Contenido({ searchParams }: { searchParams: Promise<Parametros> }) {
  const parametros = await searchParams;
  const lista = parametro(parametros, 'vista') === 'lista';

  return (
    <div className="space-y-4">
      <nav aria-label="Vista del pipeline" className="flex gap-2 text-sm">
        <Link href={RUTA} aria-current={lista ? undefined : 'page'} className={`rounded-lg px-3 py-1.5 font-semibold ${lista ? 'text-panel-muted hover:text-panel-text' : 'bg-servicios-50 text-servicios-800'}`}>Tablero</Link>
        <Link href={`${RUTA}?vista=lista`} aria-current={lista ? 'page' : undefined} className={`rounded-lg px-3 py-1.5 font-semibold ${lista ? 'bg-servicios-50 text-servicios-800' : 'text-panel-muted hover:text-panel-text'}`}>Lista</Link>
      </nav>
      {lista ? <Lista parametros={parametros} /> : <Tablero />}
    </div>
  );
}

async function Tablero() {
  const resultado = await consultarPanel<{ data: ColumnaTablero[]; meta: { tarjetas_por_columna: number } }>('servicios/oportunidades/tablero', RUTA);
  if (resultado.estado === 'prohibido') return <EstadoProhibido volver="/admin/servicios" />;
  if (resultado.estado !== 'ok') return <EstadoError texto="Inténtalo de nuevo en unos minutos." />;

  const { data: columnas, meta } = resultado.datos;
  if (columnas.every((c) => c.total === 0)) return <EstadoVacio titulo="Todavía no hay oportunidades registradas" />;

  return <TableroOportunidades columnas={columnas} porColumna={meta.tarjetas_por_columna} />;
}

async function Lista({ parametros }: { parametros: Parametros }) {
  const cadena = consulta(parametros, PARAMETROS);
  const resultado = await consultarPanel<{ data: OportunidadResumen[]; meta: MetaListado }>(`servicios/oportunidades${cadena}`, `${RUTA}${cadena}`);
  if (resultado.estado === 'prohibido') return <EstadoProhibido volver="/admin/servicios" />;
  if (resultado.estado !== 'ok') return <EstadoError texto="Inténtalo de nuevo en unos minutos." />;

  const { data: oportunidades, meta } = resultado.datos;
  const hayFiltros = Object.keys(meta.filtros).length > 0;
  const actuales = { vista: 'lista', ...Object.fromEntries(PARAMETROS.filter((n) => n !== 'page' && parametro(parametros, n)).map((n) => [n, parametro(parametros, n)])) };

  return (
    <div className="space-y-4">
      <FiltrosListado tono="servicios" ruta={`${RUTA}?vista=lista`} hayFiltros={hayFiltros}>
        <input type="hidden" name="vista" value="lista" />
        <CampoBusqueda tono="servicios" nombre="busqueda" valor={parametro(parametros, 'busqueda')} marcador="Título de la oportunidad" />
        <SelectorFiltro tono="servicios" nombre="etapa" etiqueta="Etapa" valor={parametro(parametros, 'etapa')} opciones={ETIQUETAS.etapaOportunidad} />
        <label className="flex items-center gap-2 pb-2 text-sm text-panel-text">
          <input type="checkbox" name="abiertas" value="1" defaultChecked={parametro(parametros, 'abiertas') === '1'} className="h-4 w-4 accent-servicios-700" />
          Solo abiertas
        </label>
      </FiltrosListado>

      {oportunidades.length === 0 ? (
        hayFiltros ? <EstadoSinResultados titulo="Ninguna oportunidad coincide con los filtros" /> : <EstadoVacio titulo="Todavía no hay oportunidades registradas" />
      ) : (
        <div className="overflow-hidden rounded-xl border border-panel-border bg-panel-surface shadow-sm">
          <div className="overflow-x-auto">
            <table className="w-full text-left text-sm">
              <caption className="sr-only">Oportunidades ({meta.total})</caption>
              <thead className="border-b border-panel-border bg-panel-bg text-xs uppercase tracking-wide text-panel-muted">
                <tr>
                  <th scope="col" className="px-4 py-3 font-semibold">Oportunidad</th>
                  <th scope="col" className="px-4 py-3 font-semibold">Etapa</th>
                  <th scope="col" className="px-4 py-3 text-right font-semibold">Monto</th>
                  <th scope="col" className="px-4 py-3 font-semibold">Probabilidad</th>
                  <th scope="col" className="px-4 py-3 font-semibold">Responsable</th>
                  <th scope="col" className="px-4 py-3 font-semibold">Cierre estimado</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-panel-border">
                {oportunidades.map((o) => (
                  <tr key={o.identificador} className="hover:bg-servicios-50/50">
                    <td className="px-4 py-3">
                      <Link href={`${RUTA}/${o.identificador}`} className="font-semibold text-panel-text hover:text-servicios-800 hover:underline">{o.titulo}</Link>
                      {o.cliente ? <p className="text-xs text-panel-muted">{o.cliente.nombre}</p> : null}
                    </td>
                    <td className="px-4 py-3"><Insignia color={colorEstado(o.etapa)}>{etiqueta('etapaOportunidad', o.etapa)}</Insignia></td>
                    <td className="whitespace-nowrap px-4 py-3 text-right text-panel-text">{moneda(o.monto_estimado)}</td>
                    <td className="px-4 py-3 text-panel-muted">{o.probabilidad} %</td>
                    <td className="px-4 py-3 text-panel-muted">{o.responsable ?? 'Sin asignar'}</td>
                    <td className="whitespace-nowrap px-4 py-3 text-panel-muted">{fecha(o.fecha_cierre_estimada)}</td>
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
