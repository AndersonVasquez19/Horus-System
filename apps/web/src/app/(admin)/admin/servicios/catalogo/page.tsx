import { Suspense } from 'react';
import { EncabezadoPagina } from '@/components/shared/ui/encabezado-pagina';
import { EstadoError, EstadoProhibido, EstadoSinResultados, EstadoVacio } from '@/components/shared/ui/estados';
import { CampoBusqueda, FiltrosListado, SelectorFiltro, parametro } from '@/components/shared/ui/filtros-listado';
import { Insignia, colorEstado } from '@/components/shared/ui/insignia';
import { Paginacion, type MetaListado } from '@/components/shared/ui/paginacion';
import type { ServicioResumen } from '@/features/servicios/tipos';
import { consulta, consultarPanel } from '@/lib/panel/api-servidor';
import { ETIQUETAS, etiqueta, fecha } from '@/lib/panel/formato';

export const metadata = { title: 'Servicios · Servicios tecnológicos' };

const RUTA = '/admin/servicios/catalogo';
const PARAMETROS = ['busqueda', 'estado', 'publicacion', 'sort', 'page'] as const;
type Parametros = Record<string, string | string[] | undefined>;

export default function CatalogoPage({ searchParams }: { searchParams: Promise<Parametros> }) {
  return (
    <>
      <EncabezadoPagina
        titulo="Servicios"
        descripcion="Catálogo interno de servicios tecnológicos y estado de su ficha pública."
        migas={[{ etiqueta: 'Inicio', href: '/admin/servicios' }, { etiqueta: 'Servicios' }]}
      />
      <Suspense fallback={<div className="h-96 animate-pulse rounded-xl border border-panel-border bg-panel-surface" aria-busy="true" aria-label="Cargando servicios" />}>
        <Listado searchParams={searchParams} />
      </Suspense>
    </>
  );
}

async function Listado({ searchParams }: { searchParams: Promise<Parametros> }) {
  const parametros = await searchParams;
  const cadena = consulta(parametros, PARAMETROS);
  const resultado = await consultarPanel<{ data: ServicioResumen[]; meta: MetaListado }>(`servicios/servicios${cadena}`, `${RUTA}${cadena}`);
  if (resultado.estado === 'prohibido') return <EstadoProhibido volver="/admin/servicios" />;
  if (resultado.estado !== 'ok') return <EstadoError texto="Inténtalo de nuevo en unos minutos." />;

  const { data: servicios, meta } = resultado.datos;
  const hayFiltros = Object.keys(meta.filtros).length > 0;
  const actuales = Object.fromEntries(PARAMETROS.filter((n) => n !== 'page' && parametro(parametros, n)).map((n) => [n, parametro(parametros, n)]));

  return (
    <div className="space-y-4">
      <FiltrosListado tono="servicios" ruta={RUTA} hayFiltros={hayFiltros}>
        <CampoBusqueda tono="servicios" nombre="busqueda" valor={parametro(parametros, 'busqueda')} marcador="Código, nombre o categoría" />
        <SelectorFiltro tono="servicios" nombre="estado" etiqueta="Estado" valor={parametro(parametros, 'estado')} opciones={ETIQUETAS.estadoServicio} />
        <SelectorFiltro tono="servicios" nombre="publicacion" etiqueta="Ficha pública" valor={parametro(parametros, 'publicacion')} opciones={ETIQUETAS.estadoPublicacion} />
        <SelectorFiltro
          tono="servicios"
          nombre="sort"
          etiqueta="Ordenar por"
          valor={parametro(parametros, 'sort') || 'nombre'}
          opciones={{ nombre: 'Nombre (A–Z)', '-nombre': 'Nombre (Z–A)', codigo: 'Código', '-actualizado': 'Actualizados recientemente' }}
          todos={null}
        />
      </FiltrosListado>

      {servicios.length === 0 ? (
        hayFiltros ? <EstadoSinResultados titulo="Ningún servicio coincide con los filtros" /> : <EstadoVacio titulo="Todavía no hay servicios registrados" />
      ) : (
        <div className="overflow-hidden rounded-xl border border-panel-border bg-panel-surface shadow-sm">
          <div className="overflow-x-auto">
            <table className="w-full text-left text-sm">
              <caption className="sr-only">Servicios ({meta.total})</caption>
              <thead className="border-b border-panel-border bg-panel-bg text-xs uppercase tracking-wide text-panel-muted">
                <tr>
                  <th scope="col" className="px-4 py-3 font-semibold">Servicio</th>
                  <th scope="col" className="px-4 py-3 font-semibold">Categoría</th>
                  <th scope="col" className="px-4 py-3 font-semibold">Estado</th>
                  <th scope="col" className="px-4 py-3 font-semibold">Ficha pública</th>
                  <th scope="col" className="px-4 py-3 font-semibold">Actualizado</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-panel-border">
                {servicios.map((servicio) => (
                  <tr key={servicio.id} className="hover:bg-servicios-50/50">
                    <td className="px-4 py-3">
                      <p className="font-semibold text-panel-text">{servicio.nombre}</p>
                      <p className="text-xs text-panel-muted">{servicio.codigo}</p>
                    </td>
                    <td className="px-4 py-3 text-panel-muted">{servicio.categoria ?? '—'}</td>
                    <td className="px-4 py-3"><Insignia color={colorEstado(servicio.estado)}>{etiqueta('estadoServicio', servicio.estado)}</Insignia></td>
                    <td className="px-4 py-3">
                      {servicio.publicacion ? (
                        <div className="space-y-1">
                          <Insignia color={colorEstado(servicio.publicacion.estado)}>{etiqueta('estadoPublicacion', servicio.publicacion.estado)}</Insignia>
                          {servicio.publicacion.estado === 'publicada' ? (
                            <p className="text-xs text-panel-muted">Hasta {fecha(servicio.publicacion.vigente_hasta)}</p>
                          ) : null}
                        </div>
                      ) : (
                        <Insignia>Sin ficha</Insignia>
                      )}
                    </td>
                    <td className="whitespace-nowrap px-4 py-3 text-panel-muted">{fecha(servicio.actualizado_en)}</td>
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
