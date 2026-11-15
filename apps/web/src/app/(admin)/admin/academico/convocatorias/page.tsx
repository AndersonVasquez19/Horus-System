import { Suspense } from 'react';
import Link from 'next/link';
import { BotonNuevaConvocatoria } from '@/components/academico/acciones-convocatoria';
import { EncabezadoPagina } from '@/components/shared/ui/encabezado-pagina';
import { EstadoError, EstadoProhibido, EstadoSinResultados, EstadoVacio } from '@/components/shared/ui/estados';
import { CampoBusqueda, FiltrosListado, SelectorFiltro, parametro } from '@/components/shared/ui/filtros-listado';
import { Insignia, colorEstado } from '@/components/shared/ui/insignia';
import { Paginacion, type MetaListado } from '@/components/shared/ui/paginacion';
import type { ConvocatoriaResumen } from '@/features/academico/tipos';
import { consulta, consultarPanel } from '@/lib/panel/api-servidor';
import { ETIQUETAS, etiqueta, fecha } from '@/lib/panel/formato';

export const metadata = { title: 'Convocatorias · Gestión académica' };

const RUTA = '/admin/academico/convocatorias';
const PARAMETROS = ['busqueda', 'estado', 'vigentes', 'sort', 'page'] as const;
type Parametros = Record<string, string | string[] | undefined>;

export default function ConvocatoriasPage({ searchParams }: { searchParams: Promise<Parametros> }) {
  return (
    <>
      <EncabezadoPagina
        titulo="Convocatorias"
        descripcion="Ediciones de un curso con sus fechas, cupos y horario. El curso sigue siendo el catálogo."
        migas={[{ etiqueta: 'Inicio', href: '/admin/academico' }, { etiqueta: 'Convocatorias' }]}
        acciones={<BotonNuevaConvocatoria />}
      />
      <Suspense fallback={<div className="h-96 animate-pulse rounded-xl border border-panel-border bg-panel-surface" aria-busy="true" aria-label="Cargando convocatorias" />}>
        <Listado searchParams={searchParams} />
      </Suspense>
    </>
  );
}

async function Listado({ searchParams }: { searchParams: Promise<Parametros> }) {
  const parametros = await searchParams;
  const cadena = consulta(parametros, PARAMETROS);
  const resultado = await consultarPanel<{ data: ConvocatoriaResumen[]; meta: MetaListado }>(`academico/convocatorias${cadena}`, `${RUTA}${cadena}`);
  if (resultado.estado === 'prohibido') return <EstadoProhibido volver="/admin/academico" />;
  if (resultado.estado !== 'ok') return <EstadoError texto="Inténtalo de nuevo en unos minutos." />;

  const { data: convocatorias, meta } = resultado.datos;
  const hayFiltros = Object.keys(meta.filtros).length > 0;
  const actuales = Object.fromEntries(PARAMETROS.filter((n) => n !== 'page' && parametro(parametros, n)).map((n) => [n, parametro(parametros, n)]));

  return (
    <div className="space-y-4">
      <FiltrosListado tono="academico" ruta={RUTA} hayFiltros={hayFiltros}>
        <CampoBusqueda tono="academico" nombre="busqueda" valor={parametro(parametros, 'busqueda')} marcador="Código o nombre" />
        <SelectorFiltro tono="academico" nombre="estado" etiqueta="Estado" valor={parametro(parametros, 'estado')} opciones={ETIQUETAS.estadoConvocatoria} />
        <label className="flex items-center gap-2 pb-2 text-sm text-panel-text">
          <input type="checkbox" name="vigentes" value="1" defaultChecked={parametro(parametros, 'vigentes') === '1'} className="h-4 w-4 accent-academico-700" />
          Solo vigentes
        </label>
      </FiltrosListado>

      {convocatorias.length === 0 ? (
        hayFiltros ? <EstadoSinResultados titulo="Ninguna convocatoria coincide con los filtros" /> : <EstadoVacio titulo="Todavía no hay convocatorias registradas" />
      ) : (
        <div className="overflow-hidden rounded-xl border border-panel-border bg-panel-surface shadow-sm">
          <div className="overflow-x-auto">
            <table className="w-full text-left text-sm">
              <caption className="sr-only">Convocatorias ({meta.total})</caption>
              <thead className="border-b border-panel-border bg-panel-bg text-xs uppercase tracking-wide text-panel-muted">
                <tr>
                  <th scope="col" className="px-4 py-3 font-semibold">Convocatoria</th>
                  <th scope="col" className="px-4 py-3 font-semibold">Estado</th>
                  <th scope="col" className="px-4 py-3 font-semibold">Modalidad</th>
                  <th scope="col" className="px-4 py-3 font-semibold">Periodo</th>
                  <th scope="col" className="px-4 py-3 text-right font-semibold">Cupos</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-panel-border">
                {convocatorias.map((c) => (
                  <tr key={c.identificador} className="hover:bg-academico-50/50">
                    <td className="px-4 py-3">
                      <Link href={`${RUTA}/${c.identificador}`} className="font-semibold text-panel-text hover:text-academico-800 hover:underline">{c.nombre}</Link>
                      <p className="text-xs text-panel-muted">{c.codigo}{c.curso ? ` · ${c.curso.codigo}` : ''}</p>
                    </td>
                    <td className="px-4 py-3"><Insignia color={colorEstado(c.estado)}>{etiqueta('estadoConvocatoria', c.estado)}</Insignia></td>
                    <td className="px-4 py-3 text-panel-muted">{etiqueta('modalidad', c.modalidad)}</td>
                    <td className="whitespace-nowrap px-4 py-3 text-panel-muted">{fecha(c.fecha_inicio)} – {fecha(c.fecha_fin)}</td>
                    <td className="whitespace-nowrap px-4 py-3 text-right text-panel-text">{c.cupos.disponibles} / {c.cupos.maximos}</td>
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
