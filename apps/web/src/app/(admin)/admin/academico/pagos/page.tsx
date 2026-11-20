import Link from 'next/link';
import { Suspense } from 'react';
import { BotonExportarPagos, COLOR_COBRO, ETIQUETA_COBRO } from '@/components/academico/paneles-pagos';
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

export const metadata = { title: 'Pagos · Gestión académica' };

const RUTA = '/admin/academico/pagos';
const PARAMETROS = ['busqueda', 'estado', 'desde', 'hasta', 'sort', 'per_page', 'page'] as const;
const FILTROS_EXPORTAR = ['busqueda', 'estado', 'desde', 'hasta', 'sort'] as const;
const OPCIONES_ESTADO_PAGOS = {
  ...ETIQUETA_COBRO,
  con_saldo: 'Con saldo pendiente',
  por_confirmar: 'Por confirmar',
  vencidas: 'Cuotas vencidas',
} as const;

type Parametros = Record<string, string | string[] | undefined>;

export default function PagosPage({ searchParams }: { searchParams: Promise<Parametros> }) {
  return (
    <>
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

  const encabezado = (
    <EncabezadoPagina
      titulo="Pagos"
      descripcion="Obligaciones de pago por matrícula, con saldo y estado de cobro (soles, Yape)."
      migas={[{ etiqueta: 'Inicio', href: '/admin/academico' }, { etiqueta: 'Pagos' }]}
      acciones={<BotonExportarPagos filtros={consulta(parametros, FILTROS_EXPORTAR)} />}
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
      <FiltrosListado tono="academico" ruta={RUTA} hayFiltros={hayFiltros}>
        <CampoBusqueda tono="academico" nombre="busqueda" valor={parametro(parametros, 'busqueda')} marcador="DNI, nombres, concepto o edición" />
        <SelectorFiltro tono="academico" nombre="estado" etiqueta="Estado" valor={parametro(parametros, 'estado')} opciones={OPCIONES_ESTADO_PAGOS} />
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
          <EstadoSinResultados titulo="Ninguna obligación coincide con los filtros" texto="Prueba con otros criterios o limpia los filtros." />
        ) : (
          <EstadoVacio titulo="Todavía no hay obligaciones de pago" texto="Se crean desde la ficha de cada matrícula." />
        )
      ) : (
        <Tabla
          titulo={`Obligaciones (${meta.total})`}
          columnas={['Estudiante', 'Edición', { texto: 'Total', orden: 'monto' }, 'Pagado', 'Saldo', 'Próx. vencimiento', 'Estado', { texto: 'Acciones', oculta: true, derecha: true }]}
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
              { tipo: 'enlace', etiqueta: 'Ver obligación', href: `${RUTA}/${o.identificador}` },
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
                  <Link href={`${RUTA}/${o.identificador}`} className="font-semibold text-panel-text hover:text-academico-800 hover:underline">{o.estudiante.nombre}</Link>
                  <p className="font-mono text-xs text-panel-muted">{o.estudiante.dni}</p>
                </td>
                <td className="px-4 py-3 font-mono text-xs text-panel-muted">{o.edicion ?? '—'}</td>
                <td className="px-4 py-3">{moneda(o.monto_total)}</td>
                <td className="px-4 py-3">{moneda(o.pagado)}</td>
                <td className="px-4 py-3">{moneda(o.saldo)}</td>
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
