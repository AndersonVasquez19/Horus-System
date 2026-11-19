import Link from 'next/link';
import { BookOpen, ClipboardList, Users, Wallet } from 'lucide-react';
import { Suspense } from 'react';
import { AccionesReporte } from '@/components/academico/acciones-reporte';
import { EncabezadoPagina } from '@/components/shared/ui/encabezado-pagina';
import { EstadoError, EstadoProhibido, EstadoSinResultados, EstadoVacio } from '@/components/shared/ui/estados';
import { CampoBusqueda, FiltrosListado, SelectorFiltro, parametro } from '@/components/shared/ui/filtros-listado';
import { Insignia, colorEstado } from '@/components/shared/ui/insignia';
import { Paginacion } from '@/components/shared/ui/paginacion';
import { EsqueletoListado, Tabla } from '@/components/shared/ui/tabla';
import { TarjetaKpi } from '@/components/shared/ui/tarjeta-kpi';
import { consultarPanel } from '@/lib/panel/api-servidor';
import { ETIQUETAS, etiqueta, fecha, moneda, numero } from '@/lib/panel/formato';

export const metadata = { title: 'Reportes · Gestión académica' };

const RUTA = '/admin/academico/reportes';
const FILTROS = ['busqueda', 'estado', 'eliminados'] as const;

type Parametros = Record<string, string | string[] | undefined>;

interface FilaReporte {
  id: number;
  estudiante: string | null;
  curso: { codigo: string; nombre: string } | null;
  fecha: string;
  estado: string;
  monto_pagado: string | null;
}

interface RespuestaReporte {
  data: FilaReporte[];
  resumen: { estudiantes: number; cursos: number; matriculas: number; ingresos?: string };
  meta: { lote: number; lotes: number; tamano_lote: number; total: number; filtros: Record<string, string | boolean> };
}

export default function ReportesPage({ searchParams }: { searchParams: Promise<Parametros> }) {
  return (
    <>
      <Suspense fallback={<EsqueletoListado etiqueta="Cargando el reporte" />}>
        <Reporte searchParams={searchParams} />
      </Suspense>
    </>
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

async function Reporte({ searchParams }: { searchParams: Promise<Parametros> }) {
  const parametros = await searchParams;
  const resultado = await consultarPanel<RespuestaReporte>(`academico/reportes${consultaApi(parametros, true)}`, RUTA);

  const encabezado = (acciones?: React.ReactNode) => (
    <EncabezadoPagina
      titulo="Reportes"
      descripcion="Matrículas con sus filtros, resumen y descarga en Excel o PDF."
      migas={[{ etiqueta: 'Inicio', href: '/admin/academico' }, { etiqueta: 'Reportes' }]}
      acciones={acciones}
    />
  );

  if (resultado.estado === 'prohibido') return <>{encabezado()}<EstadoProhibido volver="/admin/academico" /></>;
  if (resultado.estado === 'no_encontrado') {
    // El lote pedido ya no existe (los filtros cambiaron): se ofrece volver al primero.
    return <>{encabezado()}<EstadoSinResultados titulo="Ese lote ya no existe" texto="Los resultados cambiaron." accion={<Link href={RUTA} className="text-sm font-semibold text-academico-700 hover:underline">Ver el reporte desde el inicio</Link>} /></>;
  }
  if (resultado.estado !== 'ok') return <>{encabezado()}<EstadoError texto="Inténtalo de nuevo en unos minutos." /></>;

  const { data: filas, resumen, meta } = resultado.datos;
  const actuales = Object.fromEntries(FILTROS.filter((nombre) => parametro(parametros, nombre) !== '').map((nombre) => [nombre, parametro(parametros, nombre)]));
  const hayFiltros = Object.keys(meta.filtros).length > 0;

  return (
    <>
      {encabezado(<AccionesReporte filtros={consultaApi(parametros, false)} filtrosLote={consultaApi(parametros, true)} />)}

      <div className="space-y-4">
        <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
          <TarjetaKpi tono="academico" etiqueta="Estudiantes" valor={numero(resumen.estudiantes)} icono={<Users className="h-5 w-5" />} />
          <TarjetaKpi tono="academico" etiqueta="Cursos" valor={numero(resumen.cursos)} icono={<BookOpen className="h-5 w-5" />} />
          <TarjetaKpi tono="academico" etiqueta="Matrículas en el reporte" valor={numero(resumen.matriculas)} detalle={hayFiltros ? 'Con los filtros aplicados' : 'Sin filtros'} icono={<ClipboardList className="h-5 w-5" />} />
          {resumen.ingresos !== undefined ? (
            <TarjetaKpi tono="academico" etiqueta="Ingresos (matrículas pagadas)" valor={moneda(resumen.ingresos)} detalle="Con los filtros aplicados" icono={<Wallet className="h-5 w-5" />} />
          ) : null}
        </div>

        <FiltrosListado tono="academico" ruta={RUTA} hayFiltros={hayFiltros}>
          <CampoBusqueda tono="academico" nombre="busqueda" valor={parametro(parametros, 'busqueda')} marcador="Estudiante, DNI o curso" />
          <SelectorFiltro tono="academico" nombre="estado" etiqueta="Estado" valor={parametro(parametros, 'estado')} opciones={ETIQUETAS.estadoMatricula} />
          <label className="flex items-center gap-2 pb-2 text-sm text-panel-text">
            <input type="checkbox" name="eliminados" value="1" defaultChecked={parametro(parametros, 'eliminados') === '1'} className="h-4 w-4 rounded border-panel-border accent-academico-700" />
            Eliminadas
          </label>
        </FiltrosListado>

        {filas.length === 0 ? (
          hayFiltros ? (
            <EstadoSinResultados titulo="Ninguna matrícula coincide con los filtros" texto="Prueba con otros criterios o limpia los filtros." />
          ) : (
            <EstadoVacio titulo="Todavía no hay matrículas para reportar" />
          )
        ) : (
          <Tabla
            titulo={`Matrículas (${meta.total}) · lote ${meta.lote} de ${meta.lotes}`}
            columnas={['Estudiante', 'Curso', 'Fecha', 'Estado', 'Monto']}
            pie={
              <Paginacion
                meta={{ pagina: meta.lote, por_pagina: meta.tamano_lote, total: meta.total, paginas: meta.lotes, orden: '', filtros: meta.filtros }}
                ruta={RUTA}
                parametros={actuales}
              />
            }
          >
            {filas.map((fila) => (
              <tr key={fila.id} className="hover:bg-academico-50/50">
                <td className="px-4 py-3 font-medium text-panel-text">{fila.estudiante ?? '—'}</td>
                <td className="px-4 py-3 text-panel-muted">
                  {fila.curso?.nombre ?? '—'}
                  {fila.curso ? <span className="block text-xs">{fila.curso.codigo}</span> : null}
                </td>
                <td className="whitespace-nowrap px-4 py-3 text-panel-muted">{fecha(fila.fecha)}</td>
                <td className="px-4 py-3"><Insignia color={colorEstado(fila.estado)}>{etiqueta('estadoMatricula', fila.estado)}</Insignia></td>
                <td className="whitespace-nowrap px-4 py-3 text-panel-muted">{moneda(fila.monto_pagado)}</td>
              </tr>
            ))}
          </Tabla>
        )}
      </div>
    </>
  );
}
