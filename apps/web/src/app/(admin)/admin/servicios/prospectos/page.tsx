import { Suspense } from 'react';
import Link from 'next/link';
import { BotonNuevoProspecto } from '@/components/servicios/acciones-prospecto';
import { EncabezadoPagina } from '@/components/shared/ui/encabezado-pagina';
import { EstadoError, EstadoProhibido, EstadoSinResultados, EstadoVacio } from '@/components/shared/ui/estados';
import { CampoBusqueda, FiltrosListado, SelectorFiltro, parametro } from '@/components/shared/ui/filtros-listado';
import { Insignia, colorEstado } from '@/components/shared/ui/insignia';
import { Paginacion, type MetaListado } from '@/components/shared/ui/paginacion';
import type { ProspectoResumen } from '@/features/servicios/tipos';
import { consulta, consultarPanel } from '@/lib/panel/api-servidor';
import { ETIQUETAS, etiqueta, fecha } from '@/lib/panel/formato';

export const metadata = { title: 'Prospectos · Servicios tecnológicos' };

const RUTA = '/admin/servicios/prospectos';
const PARAMETROS = ['busqueda', 'estado', 'origen', 'seguimiento_pendiente', 'sort', 'page'] as const;
type Parametros = Record<string, string | string[] | undefined>;

export default function ProspectosPage({ searchParams }: { searchParams: Promise<Parametros> }) {
  return (
    <>
      <EncabezadoPagina
        titulo="Prospectos"
        descripcion="Contactos comerciales que aún no son clientes. El listado enmascara el correo; el detalle lo muestra completo."
        migas={[{ etiqueta: 'Inicio', href: '/admin/servicios' }, { etiqueta: 'Prospectos' }]}
        acciones={<BotonNuevoProspecto />}
      />
      <Suspense fallback={<div className="h-96 animate-pulse rounded-xl border border-panel-border bg-panel-surface" aria-busy="true" aria-label="Cargando prospectos" />}>
        <Listado searchParams={searchParams} />
      </Suspense>
    </>
  );
}

async function Listado({ searchParams }: { searchParams: Promise<Parametros> }) {
  const parametros = await searchParams;
  const cadena = consulta(parametros, PARAMETROS);
  const resultado = await consultarPanel<{ data: ProspectoResumen[]; meta: MetaListado }>(`servicios/prospectos${cadena}`, `${RUTA}${cadena}`);
  if (resultado.estado === 'prohibido') return <EstadoProhibido volver="/admin/servicios" />;
  if (resultado.estado !== 'ok') return <EstadoError texto="Inténtalo de nuevo en unos minutos." />;

  const { data: prospectos, meta } = resultado.datos;
  const hayFiltros = Object.keys(meta.filtros).length > 0;
  const actuales = Object.fromEntries(PARAMETROS.filter((n) => n !== 'page' && parametro(parametros, n)).map((n) => [n, parametro(parametros, n)]));

  return (
    <div className="space-y-4">
      <FiltrosListado tono="servicios" ruta={RUTA} hayFiltros={hayFiltros}>
        <CampoBusqueda tono="servicios" nombre="busqueda" valor={parametro(parametros, 'busqueda')} marcador="Nombre, empresa o correo" />
        <SelectorFiltro tono="servicios" nombre="estado" etiqueta="Estado" valor={parametro(parametros, 'estado')} opciones={ETIQUETAS.estadoProspecto} />
        <SelectorFiltro tono="servicios" nombre="origen" etiqueta="Origen" valor={parametro(parametros, 'origen')} opciones={ETIQUETAS.origenProspecto} />
        <label className="flex items-center gap-2 pb-2 text-sm text-panel-text">
          <input type="checkbox" name="seguimiento_pendiente" value="1" defaultChecked={parametro(parametros, 'seguimiento_pendiente') === '1'} className="h-4 w-4 accent-servicios-700" />
          Seguimiento pendiente
        </label>
      </FiltrosListado>

      {prospectos.length === 0 ? (
        hayFiltros ? <EstadoSinResultados titulo="Ningún prospecto coincide con los filtros" /> : <EstadoVacio titulo="Todavía no hay prospectos registrados" />
      ) : (
        <div className="overflow-hidden rounded-xl border border-panel-border bg-panel-surface shadow-sm">
          <div className="overflow-x-auto">
            <table className="w-full text-left text-sm">
              <caption className="sr-only">Prospectos ({meta.total})</caption>
              <thead className="border-b border-panel-border bg-panel-bg text-xs uppercase tracking-wide text-panel-muted">
                <tr>
                  <th scope="col" className="px-4 py-3 font-semibold">Contacto</th>
                  <th scope="col" className="px-4 py-3 font-semibold">Estado</th>
                  <th scope="col" className="px-4 py-3 font-semibold">Origen</th>
                  <th scope="col" className="px-4 py-3 font-semibold">Servicio de interés</th>
                  <th scope="col" className="px-4 py-3 font-semibold">Responsable</th>
                  <th scope="col" className="px-4 py-3 font-semibold">Seguimiento</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-panel-border">
                {prospectos.map((prospecto) => (
                  <tr key={prospecto.identificador} className="hover:bg-servicios-50/50">
                    <td className="px-4 py-3">
                      <Link href={`${RUTA}/${prospecto.identificador}`} className="font-semibold text-panel-text hover:text-servicios-800 hover:underline">{prospecto.nombre_contacto}</Link>
                      {prospecto.empresa ? <p className="text-xs text-panel-muted">{prospecto.empresa}</p> : null}
                    </td>
                    <td className="px-4 py-3"><Insignia color={colorEstado(prospecto.estado)}>{etiqueta('estadoProspecto', prospecto.estado)}</Insignia></td>
                    <td className="px-4 py-3 text-panel-muted">{etiqueta('origenProspecto', prospecto.origen)}</td>
                    <td className="px-4 py-3 text-panel-muted">{prospecto.servicio_interes ?? '—'}</td>
                    <td className="px-4 py-3 text-panel-muted">{prospecto.responsable ?? 'Sin asignar'}</td>
                    <td className="whitespace-nowrap px-4 py-3 text-panel-muted">{fecha(prospecto.proximo_seguimiento)}</td>
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
