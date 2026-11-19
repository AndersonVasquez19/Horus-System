import Link from 'next/link';
import { Suspense } from 'react';
import { BotonExportarPagos, COLOR_COBRO, ETIQUETA_COBRO } from '@/components/academico/paneles-pagos';
import { EncabezadoPagina } from '@/components/shared/ui/encabezado-pagina';
import { EstadoError, EstadoProhibido, EstadoSinResultados, EstadoVacio } from '@/components/shared/ui/estados';
import { CampoBusqueda, FiltrosListado, SelectorFiltro, parametro } from '@/components/shared/ui/filtros-listado';
import { Insignia } from '@/components/shared/ui/insignia';
import { Paginacion, type MetaListado } from '@/components/shared/ui/paginacion';
import { EsqueletoListado, Tabla } from '@/components/shared/ui/tabla';
import type { ObligacionResumen } from '@/features/academico/tipos';
import { consulta, consultarPanel } from '@/lib/panel/api-servidor';
import { moneda } from '@/lib/panel/formato';

export const metadata = { title: 'Pagos · Gestión académica' };

const RUTA = '/admin/academico/pagos';
const PARAMETROS = ['busqueda', 'estado', 'page'] as const;

type Parametros = Record<string, string | string[] | undefined>;

export default function PagosPage({ searchParams }: { searchParams: Promise<Parametros> }) {
  return (
    <>
      <EncabezadoPagina
        titulo="Pagos"
        descripcion="Obligaciones de pago por matrícula, con saldo y estado de cobro (soles, Yape)."
        migas={[{ etiqueta: 'Inicio', href: '/admin/academico' }, { etiqueta: 'Pagos' }]}
        acciones={<BotonExportarPagos />}
      />
      <Suspense fallback={<EsqueletoListado etiqueta="Cargando pagos" />}>
        <Listado searchParams={searchParams} />
      </Suspense>
    </>
  );
}

async function Listado({ searchParams }: { searchParams: Promise<Parametros> }) {
  const parametros = await searchParams;
  const cadena = consulta(parametros, PARAMETROS);
  const resultado = await consultarPanel<{ data: ObligacionResumen[]; meta: MetaListado }>(`academico/pagos/obligaciones${cadena}`, `${RUTA}${cadena}`);

  if (resultado.estado === 'prohibido') return <EstadoProhibido volver="/admin/academico" />;
  if (resultado.estado !== 'ok') return <EstadoError texto="Inténtalo de nuevo en unos minutos." />;

  const { data: filas, meta } = resultado.datos;
  const actuales = Object.fromEntries(PARAMETROS.filter((n) => n !== 'page' && parametro(parametros, n) !== '').map((n) => [n, parametro(parametros, n)]));
  const hayFiltros = Object.keys(meta.filtros).length > 0;

  return (
    <div className="space-y-4">
      <FiltrosListado tono="academico" ruta={RUTA} hayFiltros={hayFiltros}>
        <CampoBusqueda tono="academico" nombre="busqueda" valor={parametro(parametros, 'busqueda')} marcador="DNI, nombres o apellidos" />
        <SelectorFiltro tono="academico" nombre="estado" etiqueta="Estado" valor={parametro(parametros, 'estado')} opciones={ETIQUETA_COBRO} />
      </FiltrosListado>

      {filas.length === 0 ? (
        hayFiltros ? (
          <EstadoSinResultados titulo="Ninguna obligación coincide con los filtros" texto="Prueba con otros criterios o limpia los filtros." />
        ) : (
          <EstadoVacio titulo="Todavía no hay obligaciones de pago" texto="Se crean desde la ficha de cada matrícula." />
        )
      ) : (
        <Tabla titulo={`Obligaciones (${meta.total})`} columnas={['Estudiante', 'Edición', 'Total', 'Pagado', 'Saldo', 'Estado']} pie={<Paginacion meta={meta} ruta={RUTA} parametros={actuales} />}>
          {filas.map((o) => (
            <tr key={o.identificador} className="hover:bg-academico-50/50">
              <td className="px-4 py-3">
                <Link href={`${RUTA}/${o.identificador}`} className="font-semibold text-panel-text hover:text-academico-800 hover:underline">{o.estudiante.nombre}</Link>
                <p className="font-mono text-xs text-panel-muted">{o.estudiante.dni}</p>
              </td>
              <td className="px-4 py-3 font-mono text-xs text-panel-muted">{o.edicion ?? '—'}</td>
              <td className="px-4 py-3">{moneda(o.monto_total)}</td>
              <td className="px-4 py-3">{moneda(o.pagado)}</td>
              <td className="px-4 py-3">{moneda(o.saldo)}</td>
              <td className="px-4 py-3"><Insignia color={COLOR_COBRO[o.estado]}>{ETIQUETA_COBRO[o.estado]}</Insignia></td>
            </tr>
          ))}
        </Tabla>
      )}
    </div>
  );
}
