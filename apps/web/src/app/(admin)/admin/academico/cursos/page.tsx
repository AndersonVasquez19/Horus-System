import Link from 'next/link';
import { Suspense } from 'react';
import { BotonNuevoCurso, MenuFilaCurso } from '@/components/academico/acciones-curso';
import { BotonExportarListado } from '@/components/shared/ui/boton-exportar-listado';
import { Casilla } from '@/components/shared/ui/casilla';
import { EncabezadoPagina } from '@/components/shared/ui/encabezado-pagina';
import { EstadoError, EstadoProhibido, EstadoSinResultados, EstadoVacio } from '@/components/shared/ui/estados';
import { CampoBusqueda, FiltrosListado, SelectorFiltro, SelectorPorPagina, parametro } from '@/components/shared/ui/filtros-listado';
import { Insignia, colorEstado } from '@/components/shared/ui/insignia';
import { Paginacion, type MetaListado } from '@/components/shared/ui/paginacion';
import { EsqueletoListado, Tabla } from '@/components/shared/ui/tabla';
import type { CursoResumen } from '@/features/academico/tipos';
import { consulta, consultarPanel } from '@/lib/panel/api-servidor';
import { ETIQUETAS, etiqueta, moneda, numero } from '@/lib/panel/formato';

export const metadata = { title: 'Cursos · Gestión académica' };

const RUTA = '/admin/academico/cursos';
const PARAMETROS = ['busqueda', 'estado', 'modalidad', 'categoria', 'con_cupos', 'publicado', 'eliminados', 'sort', 'per_page', 'page'] as const;

type Parametros = Record<string, string | string[] | undefined>;

export default function CursosPage({ searchParams }: { searchParams: Promise<Parametros> }) {
  return (
    <>
      <EncabezadoPagina
        titulo="Cursos"
        descripcion="Oferta académica, cupos, precios y estado de publicación de cada curso."
        migas={[{ etiqueta: 'Inicio', href: '/admin/academico' }, { etiqueta: 'Cursos' }]}
        acciones={<BotonNuevoCurso />}
      />
      <Suspense fallback={<EsqueletoListado etiqueta="Cargando cursos" />}>
        <ListadoCursos searchParams={searchParams} />
      </Suspense>
    </>
  );
}

async function ListadoCursos({ searchParams }: { searchParams: Promise<Parametros> }) {
  const parametros = await searchParams;
  const cadena = consulta(parametros, PARAMETROS);
  const resultado = await consultarPanel<{ data: CursoResumen[]; meta: MetaListado }>(`academico/cursos${cadena}`, `${RUTA}${cadena}`);

  if (resultado.estado === 'prohibido') return <EstadoProhibido volver="/admin/academico" />;
  if (resultado.estado !== 'ok') return <EstadoError texto="Inténtalo de nuevo en unos minutos." />;

  const { data: cursos, meta } = resultado.datos;
  const actuales = Object.fromEntries(
    PARAMETROS.filter((nombre) => nombre !== 'page' && parametro(parametros, nombre) !== '').map((nombre) => [nombre, parametro(parametros, nombre)]),
  );
  const hayFiltros = Object.keys(meta.filtros).length > 0;
  const ordenActual = parametro(parametros, 'sort') || 'nombre';

  return (
    <div className="space-y-4">
      <FiltrosListado tono="academico" ruta={RUTA} hayFiltros={hayFiltros}>
        <CampoBusqueda tono="academico" nombre="busqueda" valor={parametro(parametros, 'busqueda')} marcador="Código o nombre" />
        <SelectorFiltro tono="academico" nombre="estado" etiqueta="Estado" valor={parametro(parametros, 'estado')} opciones={ETIQUETAS.estadoCurso} />
        <SelectorFiltro tono="academico" nombre="modalidad" etiqueta="Modalidad" valor={parametro(parametros, 'modalidad')} opciones={ETIQUETAS.modalidad} />
        <SelectorFiltro tono="academico" nombre="categoria" etiqueta="Categoría" valor={parametro(parametros, 'categoria')} opciones={ETIQUETAS.categoria} />
        <SelectorFiltro
          tono="academico"
          nombre="publicado"
          etiqueta="Publicación"
          valor={parametro(parametros, 'publicado')}
          opciones={{ '1': 'Publicados', '0': 'No publicados' }}
        />
        <SelectorFiltro
          tono="academico"
          nombre="sort"
          etiqueta="Ordenar por"
          valor={ordenActual}
          todos={null}
          opciones={{
            nombre: 'Nombre (A–Z)',
            '-nombre': 'Nombre (Z–A)',
            codigo: 'Código',
            precio: 'Menor precio',
            '-precio': 'Mayor precio',
            duracion: 'Menor duración',
            '-duracion': 'Mayor duración',
            cupos_disponibles: 'Menos cupos disponibles',
            '-creado': 'Más recientes',
          }}
        />
        <SelectorPorPagina tono="academico" valor={parametro(parametros, 'per_page')} />
        <Casilla compacta name="con_cupos" value="1" defaultChecked={parametro(parametros, 'con_cupos') === '1'} etiqueta="Con cupos" className="pb-0.5" />
        <Casilla compacta name="eliminados" value="1" defaultChecked={parametro(parametros, 'eliminados') === '1'} etiqueta="Archivados" className="pb-0.5" />
      </FiltrosListado>

      <div className="flex justify-end">
        <BotonExportarListado
          ruta="academico/cursos/exportar"
          filtros={consulta(parametros, ['busqueda', 'estado', 'modalidad', 'categoria', 'con_cupos', 'publicado', 'eliminados', 'sort'])}
          permiso="cursos.ver"
        />
      </div>

      {cursos.length === 0 ? (
        hayFiltros ? (
          <EstadoSinResultados titulo="Ningún curso coincide con los filtros" texto="Prueba con otros criterios o limpia los filtros." />
        ) : (
          <EstadoVacio titulo="Todavía no hay cursos registrados" texto="Registra el primero con «Nuevo curso»." />
        )
      ) : (
        <Tabla accionesFijas
          titulo={`Cursos (${meta.total})`}
          ordenActual={ordenActual}
          rutaOrden={RUTA}
          parametrosOrden={actuales}
          columnas={[
            { texto: 'Curso', orden: 'nombre' },
            'Categoría',
            'Modalidad',
            { texto: 'Duración', orden: 'duracion' },
            { texto: 'Precio', orden: 'precio' },
            { texto: 'Cupos', orden: 'cupos_disponibles' },
            'Publicación',
            'Estado',
            { texto: 'Acciones', oculta: true, derecha: true },
          ]}
          pie={<Paginacion meta={meta} ruta={RUTA} parametros={actuales} />}
        >
          {cursos.map((curso) => {
            const ocupados = curso.cupos_maximos - curso.cupos_disponibles;
            const porcentaje = curso.cupos_maximos > 0 ? Math.round((ocupados / curso.cupos_maximos) * 100) : 0;
            return (
              <tr key={curso.id} className="hover:bg-academico-50/50">
                <td className="px-4 py-3">
                  <Link href={`${RUTA}/${curso.id}`} className="font-semibold text-panel-text hover:text-academico-800 hover:underline">
                    {curso.nombre}
                  </Link>
                  <p className="text-xs text-panel-muted">{curso.codigo}</p>
                </td>
                <td className="px-4 py-3 text-panel-muted">{etiqueta('categoria', curso.categoria)}</td>
                <td className="px-4 py-3 text-panel-muted">{etiqueta('modalidad', curso.modalidad)}</td>
                <td className="whitespace-nowrap px-4 py-3 text-panel-muted">{numero(curso.duracion_horas)} h</td>
                <td className="whitespace-nowrap px-4 py-3 font-medium text-panel-text">{moneda(curso.precio)}</td>
                <td className="px-4 py-3">
                  <div className="flex items-center gap-2">
                    <div className="h-1.5 w-20 overflow-hidden rounded-full bg-panel-border" aria-hidden="true">
                      <div className="h-full rounded-full bg-academico-600" style={{ width: `${porcentaje}%` }} />
                    </div>
                    <span className="whitespace-nowrap text-xs text-panel-muted">
                      {curso.cupos_disponibles} libres de {curso.cupos_maximos}
                    </span>
                  </div>
                </td>
                <td className="px-4 py-3">
                  <Insignia color={curso.publicado ? 'verde' : 'gris'}>
                    {curso.publicado ? 'Publicado' : 'Privado'}
                  </Insignia>
                </td>
                <td className="px-4 py-3">
                  <div className="flex flex-wrap gap-1">
                    <Insignia color={colorEstado(curso.estado)}>{etiqueta('estadoCurso', curso.estado)}</Insignia>
                    {curso.eliminado ? <Insignia color="rojo">Archivado</Insignia> : null}
                  </div>
                </td>
                <td className="px-4 py-3 text-right">
                  <MenuFilaCurso curso={curso} />
                </td>
              </tr>
            );
          })}
        </Tabla>
      )}
    </div>
  );
}
