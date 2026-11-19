import { Suspense } from 'react';
import Link from 'next/link';
import { BotonNuevaConvocatoria } from '@/components/academico/acciones-convocatoria';
import { BotonExportarListado } from '@/components/shared/ui/boton-exportar-listado';
import { EncabezadoPagina } from '@/components/shared/ui/encabezado-pagina';
import { EstadoError, EstadoProhibido, EstadoSinResultados, EstadoVacio } from '@/components/shared/ui/estados';
import { CampoBusqueda, CampoFecha, FiltrosListado, SelectorFiltro, SelectorPorPagina, parametro } from '@/components/shared/ui/filtros-listado';
import { Insignia, colorEstado } from '@/components/shared/ui/insignia';
import { MenuFila } from '@/components/shared/ui/menu-fila';
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
        <label className="flex items-center gap-2 pb-2 text-sm text-panel-text">
          <input type="checkbox" name="vigentes" value="1" defaultChecked={parametro(parametros, 'vigentes') === '1'} className="h-4 w-4 accent-academico-700" />
          Solo vigentes
        </label>
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
        <Tabla
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
                <p className="text-xs text-panel-muted">{c.codigo}{c.curso ? ` · ${c.curso.codigo}` : ''}</p>
              </td>
              <td className="px-4 py-3"><Insignia color={colorEstado(c.estado)}>{etiqueta('estadoConvocatoria', c.estado)}</Insignia></td>
              <td className="px-4 py-3 text-panel-muted">{etiqueta('modalidad', c.modalidad)}</td>
              <td className="whitespace-nowrap px-4 py-3 text-panel-muted">{fecha(c.fecha_inicio)} – {fecha(c.fecha_fin)}</td>
              <td className="whitespace-nowrap px-4 py-3 text-right text-panel-text">{c.cupos.disponibles} / {c.cupos.maximos}</td>
              <td className="px-4 py-3 text-right">
                <MenuFila
                  etiqueta={`Acciones para edición ${c.codigo}`}
                  elementos={[
                    { tipo: 'enlace', etiqueta: 'Ver edición', href: `${RUTA}/${c.identificador}` },
                    ...(c.curso ? [{ tipo: 'enlace' as const, etiqueta: 'Ver curso', href: `/admin/academico/cursos/${c.curso.id}` }] : []),
                    { tipo: 'copiar', etiqueta: 'Copiar código', valor: c.codigo, aviso: 'Código de edición copiado.' },
                  ]}
                />
              </td>
            </tr>
          ))}
        </Tabla>
      )}
    </div>
  );
}
