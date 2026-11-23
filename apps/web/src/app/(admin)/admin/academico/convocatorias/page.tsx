import { Suspense } from 'react';
import Link from 'next/link';
import { BookOpen, Eye } from 'lucide-react';
import { BotonNuevaConvocatoria } from '@/components/academico/acciones-convocatoria';
import { BotonCopiarDato, EnlaceAccionFila } from '@/components/shared/ui/acciones-celda';
import { BotonExportarListado } from '@/components/shared/ui/boton-exportar-listado';
import { Casilla } from '@/components/shared/ui/casilla';
import { EncabezadoPagina } from '@/components/shared/ui/encabezado-pagina';
import { EstadoError, EstadoProhibido, EstadoSinResultados, EstadoVacio } from '@/components/shared/ui/estados';
import { CampoBusqueda, CampoFecha, FiltrosListado, SelectorFiltro, SelectorPorPagina, parametro } from '@/components/shared/ui/filtros-listado';
import { Insignia, colorEstado } from '@/components/shared/ui/insignia';
import { Paginacion, type MetaListado } from '@/components/shared/ui/paginacion';
import { EsqueletoListado, Tabla } from '@/components/shared/ui/tabla';
import type { ConvocatoriaResumen } from '@/features/academico/tipos';
import { consulta, consultarPanel } from '@/lib/panel/api-servidor';
import { ETIQUETAS, etiqueta, fecha } from '@/lib/panel/formato';

export const metadata = { title: 'Ediciones · Gestión académica' };

const RUTA = '/admin/academico/convocatorias';
const PARAMETROS = ['busqueda', 'estado', 'curso_id', 'desde', 'hasta', 'vigentes', 'sort', 'per_page', 'page'] as const;
type Parametros = Record<string, string | string[] | undefined>;
interface MetaEdiciones extends MetaListado {
  cursos?: { id: number; codigo: string; nombre: string }[];
}

export default function ConvocatoriasPage({ searchParams }: { searchParams: Promise<Parametros> }) {
  return (
    <>
      <EncabezadoPagina
        titulo="Ediciones"
        descripcion="Ediciones de un curso con sus fechas, cupos y horario. El curso sigue siendo el catálogo."
        migas={[{ etiqueta: 'Inicio', href: '/admin/academico' }, { etiqueta: 'Ediciones' }]}
        acciones={<BotonNuevaConvocatoria />}
      />
      <Suspense fallback={<EsqueletoListado etiqueta="Cargando ediciones" />}>
        <Listado searchParams={searchParams} />
      </Suspense>
    </>
  );
}

async function Listado({ searchParams }: { searchParams: Promise<Parametros> }) {
  const parametros = await searchParams;
  const cadena = consulta(parametros, PARAMETROS);
  const resultado = await consultarPanel<{ data: ConvocatoriaResumen[]; meta: MetaEdiciones }>(`academico/convocatorias${cadena}`, `${RUTA}${cadena}`);
  if (resultado.estado === 'prohibido') return <EstadoProhibido volver="/admin/academico" />;
  if (resultado.estado !== 'ok') return <EstadoError texto="Inténtalo de nuevo en unos minutos." />;

  const { data: convocatorias, meta } = resultado.datos;
  const hayFiltros = Object.keys(meta.filtros).length > 0;
  const actuales = Object.fromEntries(PARAMETROS.filter((n) => n !== 'page' && parametro(parametros, n)).map((n) => [n, parametro(parametros, n)]));

  return (
    <div className="space-y-4">
      <FiltrosListado tono="academico" ruta={RUTA} hayFiltros={hayFiltros}>
        <CampoBusqueda tono="academico" nombre="busqueda" valor={parametro(parametros, 'busqueda')} marcador="Código o nombre" />
        <SelectorFiltro
          tono="academico"
          nombre="curso_id"
          etiqueta="Curso"
          valor={parametro(parametros, 'curso_id')}
          opciones={Object.fromEntries((meta.cursos ?? []).map((c) => [String(c.id), `${c.codigo} — ${c.nombre}`]))}
        />
        <SelectorFiltro tono="academico" nombre="estado" etiqueta="Estado" valor={parametro(parametros, 'estado')} opciones={ETIQUETAS.estadoConvocatoria} />
        <CampoFecha tono="academico" nombre="desde" etiqueta="Inicio desde" valor={parametro(parametros, 'desde')} />
        <CampoFecha tono="academico" nombre="hasta" etiqueta="Inicio hasta" valor={parametro(parametros, 'hasta')} />
        <SelectorFiltro
          tono="academico"
          nombre="sort"
          etiqueta="Ordenar por"
          valor={parametro(parametros, 'sort') || '-inicio'}
          todos={null}
          opciones={{ '-inicio': 'Inicio más reciente', inicio: 'Inicio más antiguo', codigo: 'Código', '-creado': 'Registradas últimamente' }}
        />
        <SelectorPorPagina tono="academico" valor={parametro(parametros, 'per_page')} />
        <Casilla compacta name="vigentes" value="1" defaultChecked={parametro(parametros, 'vigentes') === '1'} etiqueta="Solo vigentes" className="pb-0.5" />
      </FiltrosListado>

      <div className="flex justify-end">
        <BotonExportarListado
          ruta="academico/convocatorias/exportar"
          filtros={consulta(parametros, ['busqueda', 'estado', 'curso_id', 'desde', 'hasta', 'vigentes', 'sort'])}
          permiso="convocatorias.ver"
        />
      </div>

      {convocatorias.length === 0 ? (
        hayFiltros ? <EstadoSinResultados titulo="Ninguna edición coincide con los filtros" /> : <EstadoVacio titulo="Todavía no hay ediciones registradas" />
      ) : (
        <Tabla accionesFijas
          titulo={`Ediciones (${meta.total})`}
          columnas={[
            { texto: 'Edición', orden: 'codigo' },
            'Estado',
            'Modalidad',
            { texto: 'Periodo', orden: 'inicio' },
            { texto: 'Cupos', derecha: true },
            { texto: 'Acciones', oculta: true, derecha: true },
          ]}
          ordenActual={meta.orden}
          rutaOrden={RUTA}
          parametrosOrden={actuales}
          pie={<Paginacion meta={meta} ruta={RUTA} parametros={actuales} />}
        >
          {convocatorias.map((c) => (
            <tr key={c.identificador} className="hover:bg-academico-50/50">
              <td className="px-4 py-3">
                <Link href={`${RUTA}/${c.identificador}`} className="font-semibold text-panel-text hover:text-academico-800 hover:underline">{c.nombre}</Link>
                <div className="mt-0.5 flex items-center gap-1.5 text-xs text-panel-muted">
                  <span className="font-mono">{c.codigo}</span>
                  <BotonCopiarDato valor={c.codigo} etiqueta={`Copiar código ${c.codigo}`} />
                  {c.curso ? <span>· {c.curso.codigo}</span> : null}
                </div>
              </td>
              <td className="px-4 py-3"><Insignia color={colorEstado(c.estado)}>{etiqueta('estadoConvocatoria', c.estado)}</Insignia></td>
              <td className="px-4 py-3 text-panel-muted">{etiqueta('modalidad', c.modalidad)}</td>
              <td className="whitespace-nowrap px-4 py-3 text-panel-muted">{fecha(c.fecha_inicio)} – {fecha(c.fecha_fin)}</td>
              <td className="whitespace-nowrap px-4 py-3 text-right text-panel-text">
                {(() => {
                  const ocupados = Math.max(0, c.cupos.maximos - c.cupos.disponibles);
                  const pct = c.cupos.maximos > 0 ? Math.min(100, Math.round((ocupados / c.cupos.maximos) * 100)) : 0;
                  const lleno = c.cupos.disponibles <= 0;
                  return (
                    <div className="inline-flex items-center justify-end gap-2">
                      <div className="h-1.5 w-16 overflow-hidden rounded-full bg-panel-border" aria-hidden="true">
                        <div className={`h-full rounded-full ${lleno ? 'bg-red-600' : 'bg-academico-600'}`} style={{ width: `${pct}%` }} />
                      </div>
                      {lleno ? (
                        <Insignia color="rojo">Lleno ({c.cupos.maximos})</Insignia>
                      ) : (
                        <span className="text-xs tabular-nums text-panel-muted">{c.cupos.disponibles} / {c.cupos.maximos}</span>
                      )}
                    </div>
                  );
                })()}
              </td>
              <td className="px-4 py-3 text-right">
                <div className="inline-flex items-center justify-end gap-1.5">
                  <EnlaceAccionFila href={`${RUTA}/${c.identificador}`} etiqueta="Abrir" tono="primario" icono={<Eye className="h-3.5 w-3.5" aria-hidden="true" />} />
                  {c.curso ? (
                    <EnlaceAccionFila href={`/admin/academico/cursos/${c.curso.id}`} etiqueta="Curso" icono={<BookOpen className="h-3.5 w-3.5" aria-hidden="true" />} />
                  ) : null}
                </div>
              </td>
            </tr>
          ))}
        </Tabla>
      )}
    </div>
  );
}
