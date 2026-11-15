import { Suspense } from 'react';
import Link from 'next/link';
import { BotonNuevoCliente } from '@/components/servicios/acciones-cliente';
import { EncabezadoPagina } from '@/components/shared/ui/encabezado-pagina';
import { EstadoError, EstadoProhibido, EstadoSinResultados, EstadoVacio } from '@/components/shared/ui/estados';
import { CampoBusqueda, FiltrosListado, SelectorFiltro, parametro } from '@/components/shared/ui/filtros-listado';
import { Insignia, colorEstado } from '@/components/shared/ui/insignia';
import { Paginacion, type MetaListado } from '@/components/shared/ui/paginacion';
import type { ClienteResumen } from '@/features/servicios/tipos';
import { consulta, consultarPanel } from '@/lib/panel/api-servidor';
import { ETIQUETAS, etiqueta } from '@/lib/panel/formato';

export const metadata = { title: 'Clientes · Servicios tecnológicos' };

const RUTA = '/admin/servicios/clientes';
const PARAMETROS = ['busqueda', 'estado', 'tipo', 'sort', 'page'] as const;
type Parametros = Record<string, string | string[] | undefined>;

export default function ClientesPage({ searchParams }: { searchParams: Promise<Parametros> }) {
  return (
    <>
      <EncabezadoPagina
        titulo="Clientes"
        descripcion="Personas y empresas a las que se presta servicio. El listado enmascara el correo y el documento; el detalle los muestra completos."
        migas={[{ etiqueta: 'Inicio', href: '/admin/servicios' }, { etiqueta: 'Clientes' }]}
        acciones={<BotonNuevoCliente />}
      />
      <Suspense fallback={<div className="h-96 animate-pulse rounded-xl border border-panel-border bg-panel-surface" aria-busy="true" aria-label="Cargando clientes" />}>
        <Listado searchParams={searchParams} />
      </Suspense>
    </>
  );
}

async function Listado({ searchParams }: { searchParams: Promise<Parametros> }) {
  const parametros = await searchParams;
  const cadena = consulta(parametros, PARAMETROS);
  const resultado = await consultarPanel<{ data: ClienteResumen[]; meta: MetaListado }>(`servicios/clientes${cadena}`, `${RUTA}${cadena}`);
  if (resultado.estado === 'prohibido') return <EstadoProhibido volver="/admin/servicios" />;
  if (resultado.estado !== 'ok') return <EstadoError texto="Inténtalo de nuevo en unos minutos." />;

  const { data: clientes, meta } = resultado.datos;
  const hayFiltros = Object.keys(meta.filtros).length > 0;
  const actuales = Object.fromEntries(PARAMETROS.filter((n) => n !== 'page' && parametro(parametros, n)).map((n) => [n, parametro(parametros, n)]));

  return (
    <div className="space-y-4">
      <FiltrosListado tono="servicios" ruta={RUTA} hayFiltros={hayFiltros}>
        <CampoBusqueda tono="servicios" nombre="busqueda" valor={parametro(parametros, 'busqueda')} marcador="Nombre, correo o documento" />
        <SelectorFiltro tono="servicios" nombre="tipo" etiqueta="Tipo" valor={parametro(parametros, 'tipo')} opciones={ETIQUETAS.tipoCliente} />
        <SelectorFiltro tono="servicios" nombre="estado" etiqueta="Estado" valor={parametro(parametros, 'estado')} opciones={ETIQUETAS.estadoCliente} />
      </FiltrosListado>

      {clientes.length === 0 ? (
        hayFiltros ? <EstadoSinResultados titulo="Ningún cliente coincide con los filtros" /> : <EstadoVacio titulo="Todavía no hay clientes registrados" />
      ) : (
        <div className="overflow-hidden rounded-xl border border-panel-border bg-panel-surface shadow-sm">
          <div className="overflow-x-auto">
            <table className="w-full text-left text-sm">
              <caption className="sr-only">Clientes ({meta.total})</caption>
              <thead className="border-b border-panel-border bg-panel-bg text-xs uppercase tracking-wide text-panel-muted">
                <tr>
                  <th scope="col" className="px-4 py-3 font-semibold">Cliente</th>
                  <th scope="col" className="px-4 py-3 font-semibold">Tipo</th>
                  <th scope="col" className="px-4 py-3 font-semibold">Documento</th>
                  <th scope="col" className="px-4 py-3 font-semibold">Correo</th>
                  <th scope="col" className="px-4 py-3 font-semibold">Estado</th>
                  <th scope="col" className="px-4 py-3 font-semibold">Responsable</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-panel-border">
                {clientes.map((cliente) => (
                  <tr key={cliente.identificador} className="hover:bg-servicios-50/50">
                    <td className="px-4 py-3">
                      <Link href={`${RUTA}/${cliente.identificador}`} className="font-semibold text-panel-text hover:text-servicios-800 hover:underline">{cliente.nombre}</Link>
                    </td>
                    <td className="px-4 py-3 text-panel-muted">{etiqueta('tipoCliente', cliente.tipo)}</td>
                    <td className="whitespace-nowrap px-4 py-3 text-panel-muted">
                      {cliente.documento ? `${etiqueta('documentoCliente', cliente.documento_tipo)} ${cliente.documento}` : '—'}
                    </td>
                    <td className="px-4 py-3 text-panel-muted">{cliente.email ?? '—'}</td>
                    <td className="px-4 py-3"><Insignia color={colorEstado(cliente.estado)}>{etiqueta('estadoCliente', cliente.estado)}</Insignia></td>
                    <td className="px-4 py-3 text-panel-muted">{cliente.responsable ?? 'Sin asignar'}</td>
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
