import Link from 'next/link';
import { Eye, FileText } from 'lucide-react';
import { Suspense } from 'react';
import { MiniaturaComprobante } from '@/components/academico/comprobante-miniatura';
import { PagosVistas } from '@/components/academico/pagos-vistas';
import { BotonExportarPagos } from '@/components/academico/paneles-pagos';
import { BotonSubirComprobante } from '@/components/academico/subir-comprobante';
import { EnlaceAccionFila } from '@/components/shared/ui/acciones-celda';
import { EncabezadoPagina } from '@/components/shared/ui/encabezado-pagina';
import { EstadoError, EstadoProhibido, EstadoSinResultados, EstadoVacio } from '@/components/shared/ui/estados';
import { CampoBusqueda, CampoFecha, FiltrosListado, SelectorFiltro, SelectorPorPagina, parametro } from '@/components/shared/ui/filtros-listado';
import { Insignia } from '@/components/shared/ui/insignia';
import { Paginacion, type MetaListado } from '@/components/shared/ui/paginacion';
import { EsqueletoListado, Tabla } from '@/components/shared/ui/tabla';
import type { ComprobanteFila } from '@/features/academico/tipos';
import { consulta, consultarPanel } from '@/lib/panel/api-servidor';
import { fecha, moneda } from '@/lib/panel/formato';

export const metadata = { title: 'Comprobantes · Pagos · Gestión académica' };

const RUTA = '/admin/academico/pagos/comprobantes';
const PARAMETROS = ['busqueda', 'estado', 'comprobante', 'desde', 'hasta', 'sort', 'per_page', 'page'] as const;
const COLOR_ESTADO = { pendiente: 'ambar', confirmada: 'verde', rechazada: 'rojo' } as const;
const ETIQUETA_ESTADO = { pendiente: 'Por confirmar', confirmada: 'Confirmado', rechazada: 'Rechazado' } as const;

type Parametros = Record<string, string | string[] | undefined>;

export default function ComprobantesPage({ searchParams }: { searchParams: Promise<Parametros> }) {
  return (
    <Suspense fallback={<EsqueletoListado etiqueta="Cargando comprobantes" />}>
      <ListadoComprobantes searchParams={searchParams} />
    </Suspense>
  );
}

async function ListadoComprobantes({ searchParams }: { searchParams: Promise<Parametros> }) {
  const parametros = await searchParams;
  const cadena = consulta(parametros, PARAMETROS);
  const resultado = await consultarPanel<{ data: ComprobanteFila[]; meta: MetaListado }>(`academico/pagos/comprobantes${cadena}`, `${RUTA}${cadena}`);

  const encabezado = (
    <EncabezadoPagina
      titulo="Pagos y deudores"
      descripcion="Historial de comprobantes subidos (Yape, Plin, banco o efectivo) para revisión y auditoría."
      migas={[{ etiqueta: 'Inicio', href: '/admin/academico' }, { etiqueta: 'Pagos y deudores', href: '/admin/academico/pagos' }, { etiqueta: 'Comprobantes' }]}
      acciones={<div className="flex flex-wrap items-start gap-2"><BotonSubirComprobante /><BotonExportarPagos /></div>}
    />
  );

  if (resultado.estado === 'prohibido') return <>{encabezado}<EstadoProhibido volver="/admin/academico" /></>;
  if (resultado.estado !== 'ok') return <>{encabezado}<EstadoError texto="Inténtalo de nuevo en unos minutos." /></>;

  const { data: filas, meta } = resultado.datos;
  const actuales = Object.fromEntries(PARAMETROS.filter((n) => n !== 'page' && parametro(parametros, n) !== '').map((n) => [n, parametro(parametros, n)]));
  const hayFiltros = Object.keys(meta.filtros).length > 0;

  return (
    <>
      {encabezado}
      <div className="space-y-4">
        <PagosVistas activa="comprobantes" />
        <FiltrosListado tono="academico" ruta={RUTA} hayFiltros={hayFiltros}>
          <CampoBusqueda tono="academico" nombre="busqueda" valor={parametro(parametros, 'busqueda')} marcador="DNI, nombres, edición o código de operación" />
          <SelectorFiltro tono="academico" nombre="estado" etiqueta="Estado" valor={parametro(parametros, 'estado')} opciones={{ por_confirmar: 'Por confirmar', confirmada: 'Confirmado', rechazada: 'Rechazado' }} />
          <SelectorFiltro tono="academico" nombre="comprobante" etiqueta="Comprobante" valor={parametro(parametros, 'comprobante')} opciones={{ sin: 'Sin comprobante' }} />
          <CampoFecha tono="academico" nombre="desde" etiqueta="Desde" valor={parametro(parametros, 'desde')} />
          <CampoFecha tono="academico" nombre="hasta" etiqueta="Hasta" valor={parametro(parametros, 'hasta')} />
          <SelectorFiltro
            tono="academico"
            nombre="sort"
            etiqueta="Ordenar por"
            valor={parametro(parametros, 'sort') || '-fecha'}
            todos={null}
            opciones={{ '-fecha': 'Más recientes', fecha: 'Más antiguos', '-monto': 'Mayor monto', monto: 'Menor monto' }}
          />
          <SelectorPorPagina tono="academico" valor={parametro(parametros, 'per_page')} />
        </FiltrosListado>

        {filas.length === 0 ? (
          hayFiltros ? (
            <EstadoSinResultados titulo="Ningún comprobante coincide con los filtros" texto="Prueba con otros criterios o limpia los filtros." />
          ) : (
            <EstadoVacio titulo="Aún no hay comprobantes registrados" texto="Los abonos y pagos con comprobante aparecerán aquí automáticamente." />
          )
        ) : (
          <Tabla accionesFijas
            titulo={`Comprobantes (${meta.total})`}
            columnas={['Comprobante', 'Estudiante', 'Edición', { texto: 'Pagó', orden: 'monto', derecha: true }, { texto: 'Fecha', orden: 'fecha' }, { texto: 'Falta pagar', derecha: true }, 'Estado', { texto: 'Acciones', oculta: true, derecha: true }]}
            ordenActual={meta.orden}
            rutaOrden={RUTA}
            parametrosOrden={actuales}
            pie={<Paginacion meta={meta} ruta={RUTA} parametros={actuales} />}
          >
            {filas.map((p) => {
              const debe = Number(p.saldo) > 0;
              return (
                <tr key={p.identificador} className="hover:bg-academico-50/50">
                  <td className="px-4 py-3"><MiniaturaComprobante identificador={p.identificador} tiene={p.tiene_comprobante} tipo={p.tipo_comprobante} estudiante={p.estudiante.nombre} /></td>
                  <td className="px-4 py-3">
                    <Link href={`/admin/academico/pagos/${p.obligacion}`} className="font-semibold text-panel-text hover:text-academico-800 hover:underline">{p.estudiante.nombre}</Link>
                    <p className="font-mono text-xs text-panel-muted">{p.estudiante.dni}</p>
                  </td>
                  <td className="px-4 py-3 font-mono text-xs text-panel-muted">{p.edicion ?? '—'}</td>
                  <td className="whitespace-nowrap px-4 py-3 text-right tabular-nums">
                    <span className="font-semibold">{moneda(p.monto)}</span>
                    <p className="text-xs text-panel-muted">{p.metodo}{p.referencia ? ` · ${p.referencia}` : ''}</p>
                  </td>
                  <td className="px-4 py-3 text-xs">{fecha(p.fecha_pago)}</td>
                  <td className="whitespace-nowrap px-4 py-3 text-right tabular-nums">
                    {debe ? (
                      <>
                        <span className="font-semibold text-amber-700">Debe {moneda(p.saldo)}</span>
                        <p className="text-xs text-panel-muted">de {moneda(p.total)}</p>
                      </>
                    ) : (
                      <Insignia color="verde">Pagado completo</Insignia>
                    )}
                  </td>
                  <td className="px-4 py-3">
                    <div className="flex flex-wrap gap-1">
                      <Insignia color={COLOR_ESTADO[p.estado]}>{ETIQUETA_ESTADO[p.estado]}</Insignia>
                      {p.revertida ? <Insignia color="gris">Revertido</Insignia> : null}
                      {!p.tiene_comprobante ? <Insignia color="gris">Sin comprobante</Insignia> : null}
                      {p.imagen_repetida ? (
                        <span title={`La misma captura ya respalda un pago de la matrícula #${p.imagen_repetida.matricula_id} (${fecha(p.imagen_repetida.fecha_pago)})`}>
                          <Insignia color="rojo">Captura repetida</Insignia>
                        </span>
                      ) : null}
                    </div>
                  </td>
                  <td className="px-4 py-3 text-right">
                    <div className="inline-flex items-center justify-end gap-1.5">
                      <EnlaceAccionFila
                        href={`/admin/academico/pagos/${p.obligacion}`}
                        etiqueta={p.estado === 'pendiente' ? 'Revisar' : 'Ver'}
                        tono={p.estado === 'pendiente' ? 'primario' : 'neutro'}
                        icono={<Eye className="h-3.5 w-3.5" aria-hidden="true" />}
                      />
                      <EnlaceAccionFila
                        href={`/admin/academico/matriculas/${p.matricula_id}`}
                        etiqueta={`#${p.matricula_id}`}
                        icono={<FileText className="h-3.5 w-3.5" aria-hidden="true" />}
                      />
                    </div>
                  </td>
                </tr>
              );
            })}
          </Tabla>
        )}
      </div>
    </>
  );
}
