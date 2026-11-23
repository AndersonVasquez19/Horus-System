import Link from 'next/link';
import { Eye, FileText } from 'lucide-react';
import { Suspense } from 'react';
import { BotonExportarPagos } from '@/components/academico/paneles-pagos';
import { PagosVistas } from '@/components/academico/pagos-vistas';
import { CeldaCorreo, CeldaTelefono, EnlaceAccionFila } from '@/components/shared/ui/acciones-celda';
import { EncabezadoPagina } from '@/components/shared/ui/encabezado-pagina';
import { EstadoError, EstadoProhibido, EstadoSinResultados, EstadoVacio } from '@/components/shared/ui/estados';
import { CampoBusqueda, CampoFecha, FiltrosListado, SelectorFiltro, SelectorPorPagina, parametro } from '@/components/shared/ui/filtros-listado';
import { Insignia } from '@/components/shared/ui/insignia';
import { Paginacion, type MetaListado } from '@/components/shared/ui/paginacion';
import { EsqueletoListado, Tabla } from '@/components/shared/ui/tabla';
import type { ObligacionResumen } from '@/features/academico/tipos';
import { consulta, consultarPanel } from '@/lib/panel/api-servidor';
import { moneda } from '@/lib/panel/formato';

export const metadata = { title: 'Pagos completos · Pagos · Gestión académica' };

const RUTA = '/admin/academico/pagos/completos';
const PARAMETROS = ['busqueda', 'desde', 'hasta', 'sort', 'per_page', 'page'] as const;
const FILTROS_EXPORTAR = ['busqueda', 'estado', 'desde', 'hasta', 'sort'] as const;

type Parametros = Record<string, string | string[] | undefined>;

export default function PagosCompletosPage({ searchParams }: { searchParams: Promise<Parametros> }) {
  return (
    <Suspense fallback={<EsqueletoListado etiqueta="Cargando pagos completos" />}>
      <ListadoCompletos searchParams={searchParams} />
    </Suspense>
  );
}

async function ListadoCompletos({ searchParams }: { searchParams: Promise<Parametros> }) {
  const parametros = await searchParams;
  const efectivos = { ...parametros, estado: 'pagada' };
  const cadena = consulta(efectivos, [...PARAMETROS, 'estado']);
  const resultado = await consultarPanel<{ data: ObligacionResumen[]; meta: MetaListado }>(`academico/pagos/obligaciones${cadena}`, `${RUTA}${cadena}`);

  const encabezado = (
    <EncabezadoPagina
      titulo="Pagos y deudores"
      descripcion="Estudiantes que ya completaron el 100 % del pago de su matrícula."
      migas={[{ etiqueta: 'Inicio', href: '/admin/academico' }, { etiqueta: 'Pagos y deudores', href: '/admin/academico/pagos' }, { etiqueta: 'Pagos completos' }]}
      acciones={<BotonExportarPagos filtros={consulta(efectivos, FILTROS_EXPORTAR)} />}
    />
  );

  if (resultado.estado === 'prohibido') return <>{encabezado}<EstadoProhibido volver="/admin/academico" /></>;
  if (resultado.estado !== 'ok') return <>{encabezado}<EstadoError texto="Inténtalo de nuevo en unos minutos." /></>;

  const { data: filas, meta } = resultado.datos;
  const actuales = Object.fromEntries(PARAMETROS.filter((n) => n !== 'page' && parametro(parametros, n) !== '').map((n) => [n, parametro(parametros, n)]));
  const hayFiltros = PARAMETROS.some((n) => !['page', 'per_page', 'sort'].includes(n) && parametro(parametros, n) !== '');

  return (
    <>
      {encabezado}
      <div className="space-y-4">
        <PagosVistas activa="completos" />
        <FiltrosListado tono="academico" ruta={RUTA} hayFiltros={hayFiltros}>
          <CampoBusqueda tono="academico" nombre="busqueda" valor={parametro(parametros, 'busqueda')} marcador="DNI, nombres, curso o edición" />
          <CampoFecha tono="academico" nombre="desde" etiqueta="Desde" valor={parametro(parametros, 'desde')} />
          <CampoFecha tono="academico" nombre="hasta" etiqueta="Hasta" valor={parametro(parametros, 'hasta')} />
          <SelectorFiltro
            tono="academico"
            nombre="sort"
            etiqueta="Ordenar por"
            valor={parametro(parametros, 'sort') || '-creado'}
            todos={null}
            opciones={{ '-creado': 'Más recientes', creado: 'Más antiguas', '-monto': 'Mayor monto', monto: 'Menor monto' }}
          />
          <SelectorPorPagina tono="academico" valor={parametro(parametros, 'per_page')} />
        </FiltrosListado>

        {filas.length === 0 ? (
          hayFiltros ? (
            <EstadoSinResultados titulo="Ningún pago completo coincide con los filtros" texto="Prueba con otros criterios o limpia los filtros." />
          ) : (
            <EstadoVacio
              titulo="Aún no hay matrículas con pago completo"
              texto="En cuanto un estudiante cancele el 100 % de su matrícula, pasará automáticamente a esta tabla."
            />
          )
        ) : (
          <Tabla accionesFijas
            titulo={`Pagos completos (${meta.total})`}
            columnas={[
              'Estudiante',
              'Contacto',
              'Concepto / Edición',
              { texto: 'Total pagado', orden: 'monto', derecha: true },
              'Estado',
              { texto: 'Acciones', oculta: true, derecha: true },
            ]}
            ordenActual={meta.orden}
            rutaOrden={RUTA}
            parametrosOrden={actuales}
            pie={<Paginacion meta={meta} ruta={RUTA} parametros={actuales} />}
          >
            {filas.map((o) => (
              <tr key={o.identificador} className="hover:bg-academico-50/50">
                <td className="px-4 py-3">
                  <Link href={`/admin/academico/pagos/${o.identificador}`} className="font-semibold text-panel-text hover:text-academico-800 hover:underline">
                    {o.estudiante.nombre}
                  </Link>
                  <p className="font-mono text-xs text-panel-muted">{o.estudiante.dni}</p>
                </td>
                <td className="px-4 py-3">
                  <div className="flex flex-col gap-1">
                    <CeldaTelefono telefono={o.estudiante.telefono} />
                    <CeldaCorreo email={o.estudiante.email} conMailto />
                  </div>
                </td>
                <td className="px-4 py-3">
                  <p className="text-sm text-panel-text">{o.concepto}</p>
                  <p className="font-mono text-xs text-panel-muted">
                    Matrícula #{o.matricula_id}{o.edicion ? ` · ${o.edicion}` : ''}
                  </p>
                </td>
                <td className="whitespace-nowrap px-4 py-3 text-right font-semibold tabular-nums text-emerald-700">
                  {moneda(o.pagado)}
                </td>
                <td className="px-4 py-3">
                  <Insignia color="verde">Pagado completo</Insignia>
                </td>
                <td className="px-4 py-3 text-right">
                  <div className="inline-flex items-center justify-end gap-1.5">
                    <EnlaceAccionFila
                      href={`/admin/academico/pagos/${o.identificador}`}
                      etiqueta="Ver recibo"
                      icono={<Eye className="h-3.5 w-3.5" aria-hidden="true" />}
                    />
                    <EnlaceAccionFila
                      href={`/admin/academico/matriculas/${o.matricula_id}`}
                      etiqueta={`#${o.matricula_id}`}
                      icono={<FileText className="h-3.5 w-3.5" aria-hidden="true" />}
                    />
                  </div>
                </td>
              </tr>
            ))}
          </Tabla>
        )}
      </div>
    </>
  );
}
