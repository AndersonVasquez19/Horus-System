import Link from 'next/link';
import { Suspense } from 'react';
import { BotonExportarPagos, COLOR_COBRO, ETIQUETA_COBRO } from '@/components/academico/paneles-pagos';
import { PagosVistas } from '@/components/academico/pagos-vistas';
import { BotonSubirComprobante } from '@/components/academico/subir-comprobante';
import { EncabezadoPagina } from '@/components/shared/ui/encabezado-pagina';
import { EstadoError, EstadoProhibido, EstadoSinResultados, EstadoVacio } from '@/components/shared/ui/estados';
import { CampoBusqueda, CampoFecha, FiltrosListado, SelectorFiltro, SelectorPorPagina, parametro } from '@/components/shared/ui/filtros-listado';
import { Insignia } from '@/components/shared/ui/insignia';
import { MenuFila, type ElementoMenuFila } from '@/components/shared/ui/menu-fila';
import { Paginacion, type MetaListado } from '@/components/shared/ui/paginacion';
import { EsqueletoListado, Tabla } from '@/components/shared/ui/tabla';
import type { ObligacionResumen } from '@/features/academico/tipos';
import { consulta, consultarPanel } from '@/lib/panel/api-servidor';
import { mensajeCobro, urlMailto, urlWhatsApp } from '@/lib/panel/contacto';
import { fecha, moneda } from '@/lib/panel/formato';

export const metadata = { title: 'Quién debe · Pagos · Gestión académica' };

const RUTA = '/admin/academico/pagos/deudores';
const PARAMETROS = ['busqueda', 'estado', 'desde', 'hasta', 'sort', 'per_page', 'page'] as const;
const FILTROS_EXPORTAR = ['busqueda', 'estado', 'desde', 'hasta', 'sort'] as const;
/** Esta vista es la de quien aún debe: sin elección, solo matrículas con saldo por cobrar. */
const ESTADO_PREDETERMINADO = 'con_saldo';
const OPCIONES_ESTADO = {
  con_saldo: 'Con saldo pendiente',
  vencidas: 'Cuotas vencidas',
  por_confirmar: 'Con pagos por confirmar',
  parcial: 'Pagó una parte',
  pendiente: 'Sin pagos confirmados',
} as const;

type Parametros = Record<string, string | string[] | undefined>;

export default function DeudoresPage({ searchParams }: { searchParams: Promise<Parametros> }) {
  return (
    <Suspense fallback={<EsqueletoListado etiqueta="Cargando deudores" />}>
      <Listado searchParams={searchParams} />
    </Suspense>
  );
}

async function Listado({ searchParams }: { searchParams: Promise<Parametros> }) {
  const parametros = await searchParams;
  const estado = parametro(parametros, 'estado') in OPCIONES_ESTADO ? parametro(parametros, 'estado') : ESTADO_PREDETERMINADO;
  const efectivos = { ...parametros, estado };
  const cadena = consulta(efectivos, PARAMETROS);
  const resultado = await consultarPanel<{ data: ObligacionResumen[]; meta: MetaListado }>(`academico/pagos/obligaciones${cadena}`, `${RUTA}${cadena}`);

  const encabezado = (
    <EncabezadoPagina
      titulo="Pagos"
      descripcion="Quién todavía debe: lo pagado, lo que falta y cuándo vence (soles)."
      migas={[{ etiqueta: 'Inicio', href: '/admin/academico' }, { etiqueta: 'Pagos', href: '/admin/academico/pagos' }, { etiqueta: 'Quién debe' }]}
      acciones={<div className="flex flex-wrap items-start gap-2"><BotonSubirComprobante /><BotonExportarPagos filtros={consulta(efectivos, FILTROS_EXPORTAR)} /></div>}
    />
  );

  if (resultado.estado === 'prohibido') return <>{encabezado}<EstadoProhibido volver="/admin/academico" /></>;
  if (resultado.estado !== 'ok') return <>{encabezado}<EstadoError texto="Inténtalo de nuevo en unos minutos." /></>;

  const { data: filas, meta } = resultado.datos;
  const actuales = Object.fromEntries(PARAMETROS.filter((n) => n !== 'page' && parametro(efectivos, n) !== '').map((n) => [n, parametro(efectivos, n)]));
  // El estado predeterminado no cuenta como filtro elegido por la persona.
  const hayFiltros = PARAMETROS.some((n) => !['page', 'per_page', 'sort'].includes(n) && parametro(parametros, n) !== '');

  return (
    <>
      {encabezado}
      <div className="space-y-4">
        <PagosVistas activa="deudores" />
        <FiltrosListado tono="academico" ruta={RUTA} hayFiltros={hayFiltros}>
          <CampoBusqueda tono="academico" nombre="busqueda" valor={parametro(parametros, 'busqueda')} marcador="DNI, nombres, concepto o edición" />
          <SelectorFiltro tono="academico" nombre="estado" etiqueta="Estado" valor={estado} todos={null} opciones={OPCIONES_ESTADO} />
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
            <EstadoSinResultados titulo="Ninguna matrícula coincide con los filtros" texto="Prueba con otros criterios o limpia los filtros." />
          ) : (
            <EstadoVacio titulo="Nadie tiene saldo pendiente" texto="Cuando alguien pague solo una parte, aparecerá aquí con lo que debe." />
          )
        ) : (
          <Tabla
            titulo={`Matrículas con cobro (${meta.total})`}
            columnas={['Estudiante', 'Edición', { texto: 'Total', orden: 'monto' }, 'Pagado', 'Por confirmar', 'Debe', 'Próx. vencimiento', 'Estado', { texto: 'Acciones', oculta: true, derecha: true }]}
            ordenActual={meta.orden}
            rutaOrden={RUTA}
            parametrosOrden={actuales}
            pie={<Paginacion meta={meta} ruta={RUTA} parametros={actuales} />}
          >
            {filas.map((o) => {
              const conSaldo = (o.estado === 'pendiente' || o.estado === 'parcial') && Number(o.saldo) > 0;
              const textoCobro = mensajeCobro({ nombre: o.estudiante.nombre, concepto: o.concepto, saldo: o.saldo, edicion: o.edicion });
              const wa = conSaldo ? urlWhatsApp(o.estudiante.telefono, textoCobro) : urlWhatsApp(o.estudiante.telefono);
              const mail = urlMailto(o.estudiante.email, conSaldo ? `Recordatorio de pago · ${o.concepto}` : o.concepto, conSaldo ? textoCobro : undefined);
              const acciones: ElementoMenuFila[] = [
                { tipo: 'enlace', etiqueta: 'Ver pagos y comprobantes', href: `/admin/academico/pagos/${o.identificador}` },
                { tipo: 'enlace', etiqueta: `Ver matrícula #${o.matricula_id}`, href: `/admin/academico/matriculas/${o.matricula_id}` },
                ...(o.estudiante.id ? [{ tipo: 'enlace' as const, etiqueta: 'Ver estudiante', href: `/admin/academico/estudiantes/${o.estudiante.id}` }] : []),
                ...(wa ? [{ tipo: 'enlace' as const, etiqueta: conSaldo ? 'WhatsApp de cobro' : 'WhatsApp', href: wa, externo: true }] : []),
                ...(mail ? [{ tipo: 'enlace' as const, etiqueta: conSaldo ? 'Correo de cobro' : 'Enviar correo', href: mail, externo: true }] : []),
                ...(o.estudiante.telefono ? [{ tipo: 'copiar' as const, etiqueta: 'Copiar teléfono', valor: o.estudiante.telefono, aviso: 'Teléfono copiado.' }] : []),
                ...(o.estudiante.email ? [{ tipo: 'copiar' as const, etiqueta: 'Copiar correo', valor: o.estudiante.email, aviso: 'Correo copiado.' }] : []),
              ];
              return (
                <tr key={o.identificador} className="hover:bg-academico-50/50">
                  <td className="px-4 py-3">
                    <Link href={`/admin/academico/pagos/${o.identificador}`} className="font-semibold text-panel-text hover:text-academico-800 hover:underline">{o.estudiante.nombre}</Link>
                    <p className="font-mono text-xs text-panel-muted">{o.estudiante.dni}</p>
                  </td>
                  <td className="px-4 py-3 font-mono text-xs text-panel-muted">{o.edicion ?? '—'}</td>
                  <td className="px-4 py-3">{moneda(o.monto_total)}</td>
                  <td className="px-4 py-3">{moneda(o.pagado)}</td>
                  <td className="px-4 py-3">{Number(o.pendiente_confirmar) > 0 ? moneda(o.pendiente_confirmar) : '—'}</td>
                  <td className="px-4 py-3 font-semibold">{moneda(o.saldo)}</td>
                  <td className="px-4 py-3 text-xs">
                    {o.proxima_cuota ? (
                      <div className="flex flex-wrap items-center gap-1.5">
                        <span>{fecha(o.proxima_cuota.vence)}</span>
                        {o.proxima_cuota.vencida ? <Insignia color="rojo">Vencida</Insignia> : null}
                      </div>
                    ) : '—'}
                  </td>
                  <td className="px-4 py-3"><Insignia color={COLOR_COBRO[o.estado]}>{ETIQUETA_COBRO[o.estado]}</Insignia></td>
                  <td className="px-4 py-3 text-right">
                    <MenuFila etiqueta={`Acciones para ${o.estudiante.nombre}`} elementos={acciones} />
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
