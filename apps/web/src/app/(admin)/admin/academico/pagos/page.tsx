import Link from 'next/link';
import { Wallet } from 'lucide-react';
import { Suspense } from 'react';
import { BotonExportarPagos, COLOR_COBRO, ETIQUETA_COBRO } from '@/components/academico/paneles-pagos';
import { PagosVistas } from '@/components/academico/pagos-vistas';
import { BotonSubirComprobante } from '@/components/academico/subir-comprobante';
import { CeldaCorreo, CeldaTelefono, EnlaceAccionFila } from '@/components/shared/ui/acciones-celda';
import { EncabezadoPagina } from '@/components/shared/ui/encabezado-pagina';
import { EstadoError, EstadoProhibido, EstadoSinResultados, EstadoVacio } from '@/components/shared/ui/estados';
import { CampoBusqueda, CampoFecha, FiltrosListado, SelectorFiltro, SelectorPorPagina, parametro } from '@/components/shared/ui/filtros-listado';
import { Insignia } from '@/components/shared/ui/insignia';
import { Paginacion, type MetaListado } from '@/components/shared/ui/paginacion';
import { EsqueletoListado, Tabla } from '@/components/shared/ui/tabla';
import type { ObligacionResumen } from '@/features/academico/tipos';
import { consulta, consultarPanel } from '@/lib/panel/api-servidor';
import { mensajeCobro } from '@/lib/panel/contacto';
import { fecha, moneda } from '@/lib/panel/formato';

export const metadata = { title: 'Pendientes y deudores · Pagos · Gestión académica' };

const RUTA = '/admin/academico/pagos';
const PARAMETROS = ['busqueda', 'estado', 'desde', 'hasta', 'sort', 'per_page', 'page'] as const;
const FILTROS_EXPORTAR = ['busqueda', 'estado', 'desde', 'hasta', 'sort'] as const;
const ESTADO_PREDETERMINADO = 'con_saldo';
const OPCIONES_ESTADO = {
  con_saldo: 'Todos con saldo pendiente',
  parcial: 'Abono parcial (debe saldo)',
  pendiente: 'Sin pagos aún',
  vencidas: 'Cuotas vencidas',
  por_confirmar: 'Con abono por confirmar',
} as const;

type Parametros = Record<string, string | string[] | undefined>;

export default function PagosPage({ searchParams }: { searchParams: Promise<Parametros> }) {
  return (
    <Suspense fallback={<EsqueletoListado etiqueta="Cargando pendientes y deudores" />}>
      <ListadoDeudores searchParams={searchParams} />
    </Suspense>
  );
}

async function ListadoDeudores({ searchParams }: { searchParams: Promise<Parametros> }) {
  const parametros = await searchParams;
  const estado = parametro(parametros, 'estado') in OPCIONES_ESTADO ? parametro(parametros, 'estado') : ESTADO_PREDETERMINADO;
  const efectivos = { ...parametros, estado };
  const cadena = consulta(efectivos, PARAMETROS);
  const resultado = await consultarPanel<{ data: ObligacionResumen[]; meta: MetaListado }>(`academico/pagos/obligaciones${cadena}`, `${RUTA}${cadena}`);

  const encabezado = (
    <EncabezadoPagina
      titulo="Pagos y deudores"
      descripcion="Control separado de saldos pendientes por cobrar, pagos completos y comprobantes."
      migas={[{ etiqueta: 'Inicio', href: '/admin/academico' }, { etiqueta: 'Pagos y deudores' }]}
      acciones={<div className="flex flex-wrap items-start gap-2"><BotonSubirComprobante /><BotonExportarPagos filtros={consulta(efectivos, FILTROS_EXPORTAR)} /></div>}
    />
  );

  if (resultado.estado === 'prohibido') return <>{encabezado}<EstadoProhibido volver="/admin/academico" /></>;
  if (resultado.estado !== 'ok') return <>{encabezado}<EstadoError texto="Inténtalo de nuevo en unos minutos." /></>;

  const { data: filas, meta } = resultado.datos;
  const actuales = Object.fromEntries(PARAMETROS.filter((n) => n !== 'page' && parametro(efectivos, n) !== '').map((n) => [n, parametro(efectivos, n)]));
  const hayFiltros = PARAMETROS.some((n) => !['page', 'per_page', 'sort'].includes(n) && parametro(parametros, n) !== '');

  return (
    <>
      {encabezado}
      <div className="space-y-4">
        <PagosVistas activa="deudores" />
        <FiltrosListado tono="academico" ruta={RUTA} hayFiltros={hayFiltros}>
          <CampoBusqueda tono="academico" nombre="busqueda" valor={parametro(parametros, 'busqueda')} marcador="DNI, nombres, curso o edición" />
          <SelectorFiltro tono="academico" nombre="estado" etiqueta="Detalle de deuda" valor={estado} todos={null} opciones={OPCIONES_ESTADO} />
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
            <EstadoSinResultados titulo="Ninguna deuda coincide con los filtros" texto="Prueba con otros criterios o limpia los filtros." />
          ) : (
            <EstadoVacio
              titulo="No hay saldos pendientes por cobrar"
              texto="Cuando registres una matrícula pendiente o con abono parcial, aparecerá aquí con el monto exacto por cobrar."
            />
          )
        ) : (
          <Tabla accionesFijas
            titulo={`Pendientes y deudores (${meta.total})`}
            columnas={[
              'Estudiante',
              'Contacto',
              'Concepto / Edición',
              { texto: 'Total', orden: 'monto', derecha: true },
              { texto: 'Pagado', derecha: true },
              { texto: 'Pendiente a pagar', derecha: true },
              'Estado',
              { texto: 'Acciones', oculta: true, derecha: true },
            ]}
            ordenActual={meta.orden}
            rutaOrden={RUTA}
            parametrosOrden={actuales}
            pie={<Paginacion meta={meta} ruta={RUTA} parametros={actuales} />}
          >
            {filas.map((o) => {
              const textoCobro = mensajeCobro({ nombre: o.estudiante.nombre, concepto: o.concepto, saldo: o.saldo, edicion: o.edicion });
              const vencida = Boolean(o.proxima_cuota?.vencida);
              return (
                <tr key={o.identificador} className="hover:bg-academico-50/50">
                  <td className="px-4 py-3">
                    <Link href={`/admin/academico/pagos/${o.identificador}`} className="font-semibold text-panel-text hover:text-academico-800 hover:underline">
                      {o.estudiante.nombre}
                    </Link>
                    <p className="font-mono text-xs text-panel-muted">{o.estudiante.dni}</p>
                  </td>
                  <td className="px-4 py-3">
                    <div className="flex flex-col gap-1">
                      <CeldaTelefono telefono={o.estudiante.telefono} mensajeWa={textoCobro} />
                      <CeldaCorreo email={o.estudiante.email} conMailto />
                    </div>
                  </td>
                  <td className="px-4 py-3">
                    <p className="text-sm text-panel-text">{o.concepto}</p>
                    <p className="font-mono text-xs text-panel-muted">
                      Matrícula #{o.matricula_id}{o.edicion ? ` · ${o.edicion}` : ''}
                    </p>
                  </td>
                  <td className="whitespace-nowrap px-4 py-3 text-right tabular-nums text-panel-muted">{moneda(o.monto_total)}</td>
                  <td className="whitespace-nowrap px-4 py-3 text-right tabular-nums text-panel-muted">{moneda(o.pagado)}</td>
                  <td className="whitespace-nowrap px-4 py-3 text-right tabular-nums">
                    <span className={`font-semibold ${vencida ? 'text-red-700' : 'text-amber-700'}`}>
                      Debe {moneda(o.saldo)}
                    </span>
                    {o.proxima_cuota ? (
                      <p className="text-xs text-panel-muted">
                        Vence {fecha(o.proxima_cuota.vence)}
                      </p>
                    ) : null}
                  </td>
                  <td className="px-4 py-3">
                    <div className="flex flex-wrap gap-1">
                      <Insignia color={COLOR_COBRO[o.estado]}>{ETIQUETA_COBRO[o.estado]}</Insignia>
                      {vencida ? <Insignia color="rojo">Vencida</Insignia> : null}
                      {Number(o.pendiente_confirmar) > 0 ? (
                        <Insignia color="azul">Por confirmar {moneda(o.pendiente_confirmar)}</Insignia>
                      ) : null}
                    </div>
                  </td>
                  <td className="px-4 py-3 text-right">
                    <EnlaceAccionFila
                      href={`/admin/academico/pagos/${o.identificador}`}
                      etiqueta="Registrar abono"
                      tono="primario"
                      icono={<Wallet className="h-3.5 w-3.5" aria-hidden="true" />}
                    />
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
