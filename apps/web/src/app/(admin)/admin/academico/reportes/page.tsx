import Link from 'next/link';
import { BookOpen, ClipboardList, Users, Wallet } from 'lucide-react';
import { Suspense } from 'react';
import { AccionesReporte, type VistaPreviaReporte } from '@/components/academico/acciones-reporte';
import { EncabezadoPagina } from '@/components/shared/ui/encabezado-pagina';
import { Casilla } from '@/components/shared/ui/casilla';
import { EstadoError, EstadoProhibido, EstadoSinResultados, EstadoVacio } from '@/components/shared/ui/estados';
import { CampoBusqueda, FiltrosListado, SelectorFiltro, parametro } from '@/components/shared/ui/filtros-listado';
import { Insignia, colorEstado } from '@/components/shared/ui/insignia';
import { BarraProgreso } from '@/components/shared/ui/barra-progreso';
import { Paginacion } from '@/components/shared/ui/paginacion';
import { EsqueletoListado, Tabla } from '@/components/shared/ui/tabla';
import { TarjetaKpi } from '@/components/shared/ui/tarjeta-kpi';
import { consultarPanel } from '@/lib/panel/api-servidor';
import { ETIQUETAS, etiqueta, fecha, moneda, numero } from '@/lib/panel/formato';

export const metadata = { title: 'Reportes · Gestión académica' };

const RUTA = '/admin/academico/reportes';
const FILTROS = ['tipo', 'busqueda', 'estado', 'curso_id', 'eliminados'] as const;
const TIPOS_REPORTE = {
  matriculas: 'Matrículas',
  cobranza: 'Pagos',
} as const;

type TipoReporte = keyof typeof TIPOS_REPORTE;
type Parametros = Record<string, string | string[] | undefined>;

interface FilaMatriculas {
  id: number;
  estudiante: string | null;
  curso: { codigo: string; nombre: string } | null;
  fecha: string;
  estado: string;
  monto_pagado: string | null;
}

interface FilaCobranza {
  id: number;
  obligacion: string;
  estudiante: string | null;
  dni: string | null;
  curso: { codigo: string; nombre: string } | null;
  concepto: string;
  total: string;
  pagado: string;
  saldo: string;
  estado: string;
}

interface RespuestaReporte {
  tipo?: TipoReporte;
  data: Record<string, unknown>[];
  resumen: Record<string, unknown>;
  meta: {
    lote: number;
    lotes: number;
    tamano_lote: number;
    total: number;
    filtros: Record<string, string | boolean>;
    cursos?: { id: number; codigo: string; nombre: string }[];
  };
}

export default function ReportesPage({ searchParams }: { searchParams: Promise<Parametros> }) {
  return (
    <Suspense fallback={<EsqueletoListado etiqueta="Cargando el reporte" />}>
      <Reporte searchParams={searchParams} />
    </Suspense>
  );
}

/** Los filtros viajan a Laravel con sus nombres; la página del navegador (`page`) es el lote del reporte. */
function consultaApi(parametros: Parametros, conLote: boolean): string {
  const salida = new URLSearchParams();
  for (const nombre of FILTROS) {
    const valor = parametro(parametros, nombre).trim();
    if (valor !== '') salida.set(nombre, valor.slice(0, 200));
  }
  const lote = Number.parseInt(parametro(parametros, 'page'), 10);
  if (conLote && Number.isInteger(lote) && lote > 1) salida.set('lote', String(lote));
  const texto = salida.toString();
  return texto === '' ? '' : `?${texto}`;
}

function formatearVistaPrevia(tipo: TipoReporte, datos: RespuestaReporte): VistaPreviaReporte {
  if (tipo === 'cobranza') {
    const filas = datos.data as unknown as FilaCobranza[];
    return {
      titulo: 'Reporte de Pagos',
      subtitulo: `Obligaciones filtradas (${datos.meta.total})`,
      resumen: [
        { etiqueta: 'Obligaciones', valor: numero(Number(datos.resumen.obligaciones ?? 0)) },
        { etiqueta: 'Facturado', valor: moneda(String(datos.resumen.total_facturado ?? '0')) },
        { etiqueta: 'Cobrado', valor: moneda(String(datos.resumen.cobrado ?? '0')) },
        { etiqueta: 'Saldo pendiente', valor: moneda(String(datos.resumen.saldo_pendiente ?? '0')) },
      ],
      cabeceras: ['Matrícula', 'Estudiante', 'Curso', 'Total', 'Cobrado', 'Saldo', 'Estado'],
      filas: filas.map((f) => [
        `#${f.id}`,
        f.estudiante ?? '—',
        f.curso ? `${f.curso.codigo} · ${f.curso.nombre}` : '—',
        moneda(f.total),
        moneda(f.pagado),
        moneda(f.saldo),
        f.estado,
      ]),
    };
  }
  const filas = datos.data as unknown as FilaMatriculas[];
  return {
    titulo: 'Reporte de Matrículas',
    subtitulo: `Lote ${datos.meta.lote} de ${datos.meta.lotes} · ${datos.meta.total} coincidencias`,
    resumen: [
      { etiqueta: 'Estudiantes', valor: numero(Number(datos.resumen.estudiantes ?? 0)) },
      { etiqueta: 'Cursos', valor: numero(Number(datos.resumen.cursos ?? 0)) },
      { etiqueta: 'Matrículas', valor: numero(Number(datos.resumen.matriculas ?? 0)) },
      ...(datos.resumen.ingresos !== undefined ? [{ etiqueta: 'Ingresos', valor: moneda(String(datos.resumen.ingresos)) }] : []),
    ],
    cabeceras: ['ID', 'Estudiante', 'Curso', 'Fecha', 'Estado', 'Monto'],
    filas: filas.map((f) => [
      String(f.id),
      f.estudiante ?? '—',
      f.curso?.nombre ?? '—',
      fecha(f.fecha),
      etiqueta('estadoMatricula', f.estado),
      moneda(f.monto_pagado),
    ]),
  };
}

async function Reporte({ searchParams }: { searchParams: Promise<Parametros> }) {
  const parametros = await searchParams;
  const tipoRaw = parametro(parametros, 'tipo');
  const tipo: TipoReporte = tipoRaw in TIPOS_REPORTE ? (tipoRaw as TipoReporte) : 'matriculas';
  const resultado = await consultarPanel<RespuestaReporte>(`academico/reportes${consultaApi(parametros, true)}`, RUTA);

  const encabezado = (acciones?: React.ReactNode) => (
    <EncabezadoPagina
      titulo="Reportes"
      descripcion="Consolidados filtrados por tipo, vista previa y exportación en Excel o PDF."
      migas={[{ etiqueta: 'Inicio', href: '/admin/academico' }, { etiqueta: 'Reportes' }]}
      acciones={acciones}
    />
  );

  if (resultado.estado === 'prohibido') {
    if (tipo !== 'matriculas') {
      return (
        <>
          {encabezado()}
          <EstadoSinResultados
            titulo="Sin permiso para este tipo de reporte"
            texto="Tu rol no tiene acceso al módulo origen de este reporte."
            accion={<Link href={RUTA} className="text-sm font-semibold text-academico-700 hover:underline">Volver al reporte de matrículas</Link>}
          />
        </>
      );
    }
    return <>{encabezado()}<EstadoProhibido volver="/admin/academico" /></>;
  }
  if (resultado.estado === 'no_encontrado') {
    return <>{encabezado()}<EstadoSinResultados titulo="Ese lote ya no existe" texto="Los resultados cambiaron." accion={<Link href={RUTA} className="text-sm font-semibold text-academico-700 hover:underline">Ver el reporte desde el inicio</Link>} /></>;
  }
  if (resultado.estado !== 'ok') return <>{encabezado()}<EstadoError texto="Inténtalo de nuevo en unos minutos." /></>;

  const datos = resultado.datos;
  const { meta, resumen } = datos;
  const actuales = Object.fromEntries(FILTROS.filter((nombre) => parametro(parametros, nombre) !== '').map((nombre) => [nombre, parametro(parametros, nombre)]));
  const hayFiltros = Object.keys(meta.filtros).length > 0;
  const vistaPrevia = formatearVistaPrevia(tipo, datos);

  const opcionesEstado: Record<string, string> = tipo === 'cobranza'
    ? { pendiente: 'Pendiente', parcial: 'Parcial', pagada: 'Pagada', anulada: 'Anulada' }
    : ETIQUETAS.estadoMatricula;

  const grafico = graficoDelReporte(tipo, resumen);

  return (
    <>
      {encabezado(
        <AccionesReporte
          filtros={consultaApi(parametros, false)}
          filtrosLote={consultaApi(parametros, true)}
          tipo={tipo}
          variosLotes={meta.lotes > 1}
          vistaPrevia={vistaPrevia}
        />,
      )}

      <div className="space-y-4">
        <nav aria-label="Tipo de reporte" className="inline-flex flex-wrap gap-1 rounded-xl border border-panel-border bg-panel-surface p-1 shadow-sm">
          {(Object.entries(TIPOS_REPORTE) as [TipoReporte, string][]).map(([clave, etiquetaTipo]) => (
            <Link
              key={clave}
              href={clave === 'matriculas' ? RUTA : `${RUTA}?tipo=${clave}`}
              aria-current={tipo === clave ? 'page' : undefined}
              className={`rounded-lg px-4 py-2 text-sm font-semibold transition ${
                tipo === clave ? 'bg-academico-700 text-white' : 'text-panel-muted hover:bg-panel-bg hover:text-panel-text'
              }`}
            >
              {etiquetaTipo}
            </Link>
          ))}
        </nav>

        {tipo === 'matriculas' ? (
          <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <TarjetaKpi tono="academico" etiqueta="Estudiantes" valor={numero(Number(resumen.estudiantes ?? 0))} detalle={hayFiltros ? 'Con los filtros aplicados' : 'En el reporte'} icono={<Users className="h-5 w-5" />} />
            <TarjetaKpi tono="academico" etiqueta="Cursos" valor={numero(Number(resumen.cursos ?? 0))} detalle={hayFiltros ? 'Con los filtros aplicados' : 'En el reporte'} icono={<BookOpen className="h-5 w-5" />} />
            <TarjetaKpi tono="academico" etiqueta="Matrículas en el reporte" valor={numero(Number(resumen.matriculas ?? 0))} detalle={hayFiltros ? 'Con los filtros aplicados' : 'Sin filtros'} icono={<ClipboardList className="h-5 w-5" />} />
            {resumen.ingresos !== undefined ? (
              <TarjetaKpi tono="academico" etiqueta="Ingresos (libro de pagos)" valor={moneda(String(resumen.ingresos))} detalle="Confirmado neto del filtro" icono={<Wallet className="h-5 w-5" />} />
            ) : null}
          </div>
        ) : (
          <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <TarjetaKpi tono="academico" etiqueta="Obligaciones" valor={numero(Number(resumen.obligaciones ?? 0))} icono={<ClipboardList className="h-5 w-5" />} />
            <TarjetaKpi tono="academico" etiqueta="Total facturado" valor={moneda(String(resumen.total_facturado ?? '0'))} icono={<Wallet className="h-5 w-5" />} />
            <TarjetaKpi tono="academico" etiqueta="Cobrado neto" valor={moneda(String(resumen.cobrado ?? '0'))} icono={<Wallet className="h-5 w-5" />} />
            <TarjetaKpi tono="academico" etiqueta="Saldo pendiente" valor={moneda(String(resumen.saldo_pendiente ?? '0'))} icono={<Wallet className="h-5 w-5" />} />
          </div>
        )}

        {grafico}

        <FiltrosListado tono="academico" ruta={RUTA} hayFiltros={hayFiltros}>
          <SelectorFiltro tono="academico" nombre="tipo" etiqueta="Tipo de reporte" valor={tipo} todos={null} opciones={TIPOS_REPORTE} />
          <CampoBusqueda tono="academico" nombre="busqueda" valor={parametro(parametros, 'busqueda')} marcador="Estudiante, DNI, curso o edición" />
          <SelectorFiltro
            tono="academico"
            nombre="curso_id"
            etiqueta="Curso"
            valor={parametro(parametros, 'curso_id')}
            opciones={Object.fromEntries((meta.cursos ?? []).map((c) => [String(c.id), `${c.codigo} — ${c.nombre}`]))}
          />
          <SelectorFiltro tono="academico" nombre="estado" etiqueta="Estado" valor={parametro(parametros, 'estado')} opciones={opcionesEstado} />
          {tipo === 'matriculas' ? (
            <Casilla compacta name="eliminados" value="1" defaultChecked={parametro(parametros, 'eliminados') === '1'} etiqueta="Eliminadas" className="pb-0.5" />
          ) : null}
        </FiltrosListado>

        {datos.data.length === 0 ? (
          hayFiltros ? (
            <EstadoSinResultados titulo="Ningún registro coincide con los filtros" texto="Prueba con otros criterios o limpia los filtros." />
          ) : (
            <EstadoVacio titulo="Todavía no hay datos para este reporte" />
          )
        ) : tipo === 'cobranza' ? (
          <Tabla
            titulo={`Pagos (${meta.total}) · lote ${meta.lote} de ${meta.lotes}`}
            columnas={['Matrícula', 'Estudiante', 'Curso', { texto: 'Total', derecha: true }, { texto: 'Cobrado', derecha: true }, { texto: 'Saldo', derecha: true }, 'Estado']}
            pie={<Paginacion meta={{ pagina: meta.lote, por_pagina: meta.tamano_lote, total: meta.total, paginas: meta.lotes, orden: '', filtros: meta.filtros }} ruta={RUTA} parametros={actuales} unidad="Lote" />}
          >
            {(datos.data as unknown as FilaCobranza[]).map((fila) => (
              <tr key={fila.obligacion} className="hover:bg-academico-50/50">
                <td className="px-4 py-3 font-medium">
                  <Link href={`/admin/academico/pagos/${fila.obligacion}`} className="text-academico-700 hover:underline">#{fila.id}</Link>
                </td>
                <td className="px-4 py-3 text-panel-text">{fila.estudiante ?? '—'}</td>
                <td className="px-4 py-3 text-panel-muted">{fila.curso ? `${fila.curso.codigo} · ${fila.curso.nombre}` : '—'}</td>
                <td className="whitespace-nowrap px-4 py-3 text-right tabular-nums">{moneda(fila.total)}</td>
                <td className="whitespace-nowrap px-4 py-3 text-right tabular-nums text-emerald-700">{moneda(fila.pagado)}</td>
                <td className="whitespace-nowrap px-4 py-3 text-right tabular-nums font-semibold">{moneda(fila.saldo)}</td>
                <td className="px-4 py-3"><Insignia color={colorEstado(fila.estado)}>{opcionesEstado[fila.estado] ?? fila.estado}</Insignia></td>
              </tr>
            ))}
          </Tabla>
        ) : (
          <Tabla
            titulo={`Matrículas (${meta.total}) · lote ${meta.lote} de ${meta.lotes}`}
            columnas={['Estudiante', 'Curso', 'Fecha', 'Estado', { texto: 'Monto', derecha: true }]}
            pie={<Paginacion meta={{ pagina: meta.lote, por_pagina: meta.tamano_lote, total: meta.total, paginas: meta.lotes, orden: '', filtros: meta.filtros }} ruta={RUTA} parametros={actuales} unidad="Lote" />}
          >
            {(datos.data as unknown as FilaMatriculas[]).map((fila) => (
              <tr key={fila.id} className="hover:bg-academico-50/50">
                <td className="px-4 py-3 font-medium text-panel-text">
                  <Link href={`/admin/academico/matriculas/${fila.id}`} className="hover:text-academico-800 hover:underline">
                    {fila.estudiante ?? 'Estudiante no disponible'}
                  </Link>
                </td>
                <td className="px-4 py-3 text-panel-muted">
                  {fila.curso ? (
                    <>
                      <span className="text-panel-text">{fila.curso.nombre}</span>{' '}
                      <span className="text-xs">({fila.curso.codigo})</span>
                    </>
                  ) : '—'}
                </td>
                <td className="whitespace-nowrap px-4 py-3 text-panel-muted">{fecha(fila.fecha)}</td>
                <td className="px-4 py-3"><Insignia color={colorEstado(fila.estado)}>{etiqueta('estadoMatricula', fila.estado)}</Insignia></td>
                <td className="whitespace-nowrap px-4 py-3 text-right tabular-nums text-panel-muted">{moneda(fila.monto_pagado)}</td>
              </tr>
            ))}
          </Tabla>
        )}
      </div>
    </>
  );
}

/** Gráfico propio de cada tipo de reporte, calculado solo con el `resumen` que ya entrega Laravel (sin series por fecha). */
function graficoDelReporte(tipo: TipoReporte, resumen: Record<string, unknown>): React.ReactNode {
  const numeroDe = (valor: unknown) => {
    const n = Number(valor ?? 0);
    return Number.isFinite(n) ? n : 0;
  };

  if (tipo === 'cobranza') {
    const facturado = numeroDe(resumen.total_facturado);
    const cobrado = numeroDe(resumen.cobrado);
    const saldo = numeroDe(resumen.saldo_pendiente);
    if (facturado <= 0) return null;
    return (
      <BarraProgreso
        titulo="Avance de cobro"
        resumen={`${Math.round((cobrado / facturado) * 100)} % cobrado`}
        total={facturado}
        segmentos={[
          { etiqueta: 'Cobrado', valor: cobrado, texto: moneda(String(cobrado)), clase: 'bg-emerald-600' },
          { etiqueta: 'Saldo pendiente', valor: saldo, texto: moneda(String(saldo)), clase: 'bg-amber-500' },
        ]}
      />
    );
  }

  return null;
}
