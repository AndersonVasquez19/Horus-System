import { Suspense } from 'react';
import Link from 'next/link';
import { BotonNuevoTicket } from '@/components/servicios/acciones-ticket';
import { EncabezadoPagina } from '@/components/shared/ui/encabezado-pagina';
import { EstadoError, EstadoProhibido, EstadoSinResultados, EstadoVacio } from '@/components/shared/ui/estados';
import { CampoBusqueda, FiltrosListado, SelectorFiltro, parametro } from '@/components/shared/ui/filtros-listado';
import { Insignia, colorEstado } from '@/components/shared/ui/insignia';
import { Paginacion, type MetaListado } from '@/components/shared/ui/paginacion';
import type { TicketResumen } from '@/features/servicios/tipos';
import { consulta, consultarPanel } from '@/lib/panel/api-servidor';
import { ETIQUETAS, etiqueta, fecha } from '@/lib/panel/formato';

export const metadata = { title: 'Soporte técnico · Servicios tecnológicos' };

const RUTA = '/admin/servicios/soporte';
const PARAMETROS = ['busqueda', 'estado', 'prioridad', 'abiertos', 'vencidos', 'sort', 'page'] as const;
type Parametros = Record<string, string | string[] | undefined>;

export default function SoportePage({ searchParams }: { searchParams: Promise<Parametros> }) {
  return (
    <>
      <EncabezadoPagina
        titulo="Soporte técnico"
        descripcion="Tickets de los clientes con su plazo de atención. Los vencidos son los que pasaron el límite sin resolverse."
        migas={[{ etiqueta: 'Inicio', href: '/admin/servicios' }, { etiqueta: 'Soporte técnico' }]}
        acciones={<BotonNuevoTicket />}
      />
      <Suspense fallback={<div className="h-96 animate-pulse rounded-xl border border-panel-border bg-panel-surface" aria-busy="true" aria-label="Cargando tickets" />}>
        <Listado searchParams={searchParams} />
      </Suspense>
    </>
  );
}

async function Listado({ searchParams }: { searchParams: Promise<Parametros> }) {
  const parametros = await searchParams;
  const cadena = consulta(parametros, PARAMETROS);
  const resultado = await consultarPanel<{ data: TicketResumen[]; meta: MetaListado }>(`servicios/tickets${cadena}`, `${RUTA}${cadena}`);
  if (resultado.estado === 'prohibido') return <EstadoProhibido volver="/admin/servicios" />;
  if (resultado.estado !== 'ok') return <EstadoError texto="Inténtalo de nuevo en unos minutos." />;

  const { data: tickets, meta } = resultado.datos;
  const hayFiltros = Object.keys(meta.filtros).length > 0;
  const actuales = Object.fromEntries(PARAMETROS.filter((n) => n !== 'page' && parametro(parametros, n)).map((n) => [n, parametro(parametros, n)]));

  return (
    <div className="space-y-4">
      <FiltrosListado tono="servicios" ruta={RUTA} hayFiltros={hayFiltros}>
        <CampoBusqueda tono="servicios" nombre="busqueda" valor={parametro(parametros, 'busqueda')} marcador="Asunto o código" />
        <SelectorFiltro tono="servicios" nombre="estado" etiqueta="Estado" valor={parametro(parametros, 'estado')} opciones={ETIQUETAS.estadoTicket} />
        <SelectorFiltro tono="servicios" nombre="prioridad" etiqueta="Prioridad" valor={parametro(parametros, 'prioridad')} opciones={ETIQUETAS.prioridadTicket} />
        <label className="flex items-center gap-2 pb-2 text-sm text-panel-text">
          <input type="checkbox" name="abiertos" value="1" defaultChecked={parametro(parametros, 'abiertos') === '1'} className="h-4 w-4 accent-servicios-700" />
          Solo abiertos
        </label>
        <label className="flex items-center gap-2 pb-2 text-sm text-panel-text">
          <input type="checkbox" name="vencidos" value="1" defaultChecked={parametro(parametros, 'vencidos') === '1'} className="h-4 w-4 accent-servicios-700" />
          Solo vencidos
        </label>
      </FiltrosListado>

      {tickets.length === 0 ? (
        hayFiltros ? <EstadoSinResultados titulo="Ningún ticket coincide con los filtros" /> : <EstadoVacio titulo="Todavía no hay tickets registrados" />
      ) : (
        <div className="overflow-hidden rounded-xl border border-panel-border bg-panel-surface shadow-sm">
          <div className="overflow-x-auto">
            <table className="w-full text-left text-sm">
              <caption className="sr-only">Tickets ({meta.total})</caption>
              <thead className="border-b border-panel-border bg-panel-bg text-xs uppercase tracking-wide text-panel-muted">
                <tr>
                  <th scope="col" className="px-4 py-3 font-semibold">Ticket</th>
                  <th scope="col" className="px-4 py-3 font-semibold">Estado</th>
                  <th scope="col" className="px-4 py-3 font-semibold">Prioridad</th>
                  <th scope="col" className="px-4 py-3 font-semibold">Atiende</th>
                  <th scope="col" className="px-4 py-3 font-semibold">Vence</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-panel-border">
                {tickets.map((t) => (
                  <tr key={t.identificador} className="hover:bg-servicios-50/50">
                    <td className="px-4 py-3">
                      <Link href={`${RUTA}/${t.identificador}`} className="font-semibold text-panel-text hover:text-servicios-800 hover:underline">{t.asunto}</Link>
                      <p className="text-xs text-panel-muted">{t.codigo}{t.cliente ? ` · ${t.cliente.nombre}` : ''}</p>
                    </td>
                    <td className="px-4 py-3"><Insignia color={colorEstado(t.estado)}>{etiqueta('estadoTicket', t.estado)}</Insignia></td>
                    <td className="px-4 py-3"><Insignia color={colorEstado(t.prioridad)}>{etiqueta('prioridadTicket', t.prioridad)}</Insignia></td>
                    <td className="px-4 py-3 text-panel-muted">{t.asignado ?? 'Sin asignar'}</td>
                    <td className="whitespace-nowrap px-4 py-3">
                      {t.vencido ? <Insignia color="rojo">Vencido</Insignia> : null} <span className="text-panel-muted">{fecha(t.vence_en)}</span>
                    </td>
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
