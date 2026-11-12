import { Suspense } from 'react';
import Link from 'next/link';
import { BotonNuevaSolicitud } from '@/components/servicios/acciones-cotizacion';
import { EncabezadoPagina } from '@/components/shared/ui/encabezado-pagina';
import { EstadoError, EstadoProhibido, EstadoSinResultados, EstadoVacio } from '@/components/shared/ui/estados';
import { FiltrosListado, SelectorFiltro, claseControl, parametro } from '@/components/shared/ui/filtros-listado';
import { Insignia, colorEstado } from '@/components/shared/ui/insignia';
import { Paginacion, type MetaListado } from '@/components/shared/ui/paginacion';
import type { CotizacionResumen } from '@/features/servicios/tipos';
import { consulta, consultarPanel } from '@/lib/panel/api-servidor';
import { ETIQUETAS, etiqueta, fecha } from '@/lib/panel/formato';

export const metadata = { title: 'Cotizaciones · Servicios tecnológicos' };

const RUTA = '/admin/servicios/cotizaciones';
const PARAMETROS = ['estado', 'origen', 'codigo', 'desde', 'hasta', 'seguimiento_pendiente', 'sort', 'page'] as const;
type Parametros = Record<string, string | string[] | undefined>;

export default function CotizacionesPage({ searchParams }: { searchParams: Promise<Parametros> }) {
  return (
    <>
      <EncabezadoPagina
        titulo="Cotizaciones"
        descripcion="Bandeja de solicitudes de cotización. Los datos de contacto solo se muestran en el expediente."
        migas={[{ etiqueta: 'Inicio', href: '/admin/servicios' }, { etiqueta: 'Cotizaciones' }]}
        acciones={<BotonNuevaSolicitud />}
      />
      <Suspense fallback={<div className="h-96 animate-pulse rounded-xl border border-panel-border bg-panel-surface" aria-busy="true" aria-label="Cargando cotizaciones" />}>
        <Bandeja searchParams={searchParams} />
      </Suspense>
    </>
  );
}

async function Bandeja({ searchParams }: { searchParams: Promise<Parametros> }) {
  const parametros = await searchParams;
  const cadena = consulta(parametros, PARAMETROS);
  const resultado = await consultarPanel<{ data: CotizacionResumen[]; meta: MetaListado }>(`servicios/cotizaciones${cadena}`, `${RUTA}${cadena}`);
  if (resultado.estado === 'prohibido') return <EstadoProhibido volver="/admin/servicios" />;
  if (resultado.estado !== 'ok') return <EstadoError texto="Inténtalo de nuevo en unos minutos." />;

  const { data: cotizaciones, meta } = resultado.datos;
  const hayFiltros = Object.keys(meta.filtros).length > 0;
  const actuales = Object.fromEntries(PARAMETROS.filter((n) => n !== 'page' && parametro(parametros, n)).map((n) => [n, parametro(parametros, n)]));
  const control = claseControl('servicios');

  return (
    <div className="space-y-4">
      <FiltrosListado tono="servicios" ruta={RUTA} hayFiltros={hayFiltros}>
        <div>
          <label htmlFor="codigo" className="mb-1 block text-xs font-medium text-panel-muted">Código</label>
          <input id="codigo" name="codigo" defaultValue={parametro(parametros, 'codigo')} maxLength={40} placeholder="COT-…" className={control} />
        </div>
        <SelectorFiltro tono="servicios" nombre="estado" etiqueta="Estado" valor={parametro(parametros, 'estado')} opciones={ETIQUETAS.estadoCotizacion} />
        <SelectorFiltro tono="servicios" nombre="origen" etiqueta="Origen" valor={parametro(parametros, 'origen')} opciones={ETIQUETAS.origenCotizacion} />
        <div>
          <label htmlFor="desde" className="mb-1 block text-xs font-medium text-panel-muted">Recibida desde</label>
          <input id="desde" name="desde" type="date" defaultValue={parametro(parametros, 'desde')} className={control} />
        </div>
        <div>
          <label htmlFor="hasta" className="mb-1 block text-xs font-medium text-panel-muted">Hasta</label>
          <input id="hasta" name="hasta" type="date" defaultValue={parametro(parametros, 'hasta')} className={control} />
        </div>
        <label className="flex items-center gap-2 pb-2 text-sm text-panel-text">
          <input type="checkbox" name="seguimiento_pendiente" value="1" defaultChecked={parametro(parametros, 'seguimiento_pendiente') === '1'} className="h-4 w-4 accent-servicios-700" />
          Seguimiento pendiente
        </label>
      </FiltrosListado>

      {cotizaciones.length === 0 ? (
        hayFiltros ? <EstadoSinResultados titulo="Ninguna cotización coincide con los filtros" /> : <EstadoVacio titulo="Todavía no hay solicitudes de cotización" />
      ) : (
        <div className="overflow-hidden rounded-xl border border-panel-border bg-panel-surface shadow-sm">
          <div className="overflow-x-auto">
            <table className="w-full text-left text-sm">
              <caption className="sr-only">Cotizaciones ({meta.total})</caption>
              <thead className="border-b border-panel-border bg-panel-bg text-xs uppercase tracking-wide text-panel-muted">
                <tr>
                  <th scope="col" className="px-4 py-3 font-semibold">Código</th>
                  <th scope="col" className="px-4 py-3 font-semibold">Servicio</th>
                  <th scope="col" className="px-4 py-3 font-semibold">Estado</th>
                  <th scope="col" className="px-4 py-3 font-semibold">Responsable</th>
                  <th scope="col" className="px-4 py-3 font-semibold">Recibida</th>
                  <th scope="col" className="px-4 py-3 font-semibold">Seguimiento</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-panel-border">
                {cotizaciones.map((cotizacion) => (
                  <tr key={cotizacion.identificador} className="hover:bg-servicios-50/50">
                    <td className="px-4 py-3">
                      <Link href={`${RUTA}/${cotizacion.identificador}`} className="font-semibold text-panel-text hover:text-servicios-800 hover:underline">{cotizacion.codigo}</Link>
                      {cotizacion.origen === 'publico' ? <span className="ml-2 align-middle"><Insignia color="azul">Web</Insignia></span> : null}
                    </td>
                    <td className="px-4 py-3 text-panel-muted">{cotizacion.servicio ?? 'Sin servicio'}</td>
                    <td className="px-4 py-3"><Insignia color={colorEstado(cotizacion.estado)}>{etiqueta('estadoCotizacion', cotizacion.estado)}</Insignia></td>
                    <td className="px-4 py-3 text-panel-muted">{cotizacion.responsable ?? 'Sin asignar'}</td>
                    <td className="whitespace-nowrap px-4 py-3 text-panel-muted">{fecha(cotizacion.recibida_en)}</td>
                    <td className="whitespace-nowrap px-4 py-3 text-panel-muted">{fecha(cotizacion.proximo_seguimiento)}</td>
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
