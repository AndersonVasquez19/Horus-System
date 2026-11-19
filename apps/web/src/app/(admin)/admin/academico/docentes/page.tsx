import Link from 'next/link';
import { Suspense } from 'react';
import { BotonNuevoDocente } from '@/components/academico/acciones-docente';
import { EncabezadoPagina } from '@/components/shared/ui/encabezado-pagina';
import { EstadoError, EstadoProhibido, EstadoSinResultados, EstadoVacio } from '@/components/shared/ui/estados';
import { CampoBusqueda, FiltrosListado, SelectorFiltro, parametro } from '@/components/shared/ui/filtros-listado';
import { Insignia, colorEstado } from '@/components/shared/ui/insignia';
import { Paginacion, type MetaListado } from '@/components/shared/ui/paginacion';
import { EsqueletoListado, Tabla } from '@/components/shared/ui/tabla';
import type { DocenteResumen } from '@/features/academico/tipos';
import { consulta, consultarPanel } from '@/lib/panel/api-servidor';
import { ETIQUETAS, etiqueta } from '@/lib/panel/formato';

export const metadata = { title: 'Docentes · Gestión académica' };

const RUTA = '/admin/academico/docentes';
const PARAMETROS = ['busqueda', 'estado', 'eliminados', 'sort', 'page'] as const;

type Parametros = Record<string, string | string[] | undefined>;

export default function DocentesPage({ searchParams }: { searchParams: Promise<Parametros> }) {
  return (
    <>
      <EncabezadoPagina
        titulo="Docentes"
        descripcion="Docentes identificados por DNI y su asignación a las ediciones."
        migas={[{ etiqueta: 'Inicio', href: '/admin/academico' }, { etiqueta: 'Docentes' }]}
        acciones={<BotonNuevoDocente />}
      />
      <Suspense fallback={<EsqueletoListado etiqueta="Cargando docentes" />}>
        <ListadoDocentes searchParams={searchParams} />
      </Suspense>
    </>
  );
}

async function ListadoDocentes({ searchParams }: { searchParams: Promise<Parametros> }) {
  const parametros = await searchParams;
  const cadena = consulta(parametros, PARAMETROS);
  const resultado = await consultarPanel<{ data: DocenteResumen[]; meta: MetaListado }>(`academico/docentes${cadena}`, `${RUTA}${cadena}`);

  if (resultado.estado === 'prohibido') return <EstadoProhibido volver="/admin/academico" />;
  if (resultado.estado !== 'ok') return <EstadoError texto="Inténtalo de nuevo en unos minutos." />;

  const { data: docentes, meta } = resultado.datos;
  const actuales = Object.fromEntries(PARAMETROS.filter((n) => n !== 'page' && parametro(parametros, n) !== '').map((n) => [n, parametro(parametros, n)]));
  const hayFiltros = Object.keys(meta.filtros).length > 0;

  return (
    <div className="space-y-4">
      <FiltrosListado tono="academico" ruta={RUTA} hayFiltros={hayFiltros}>
        <CampoBusqueda tono="academico" nombre="busqueda" valor={parametro(parametros, 'busqueda')} marcador="DNI, nombres, apellidos o especialidad" />
        <SelectorFiltro tono="academico" nombre="estado" etiqueta="Estado" valor={parametro(parametros, 'estado')} opciones={ETIQUETAS.estadoActivo} />
        <SelectorFiltro
          tono="academico"
          nombre="sort"
          etiqueta="Ordenar por"
          valor={parametro(parametros, 'sort') || 'apellidos'}
          todos={null}
          opciones={{ apellidos: 'Apellidos (A–Z)', '-apellidos': 'Apellidos (Z–A)', dni: 'DNI', '-creado': 'Más recientes' }}
        />
        <label className="flex items-center gap-2 pb-2 text-sm text-panel-text">
          <input type="checkbox" name="eliminados" value="1" defaultChecked={parametro(parametros, 'eliminados') === '1'} className="h-4 w-4 rounded border-panel-border accent-academico-700" />
          Eliminados
        </label>
      </FiltrosListado>

      {docentes.length === 0 ? (
        hayFiltros ? (
          <EstadoSinResultados titulo="Ningún docente coincide con los filtros" texto="Prueba con otros criterios o limpia los filtros." />
        ) : (
          <EstadoVacio titulo="Todavía no hay docentes registrados" texto="Registra el primero con «Nuevo docente»." />
        )
      ) : (
        <Tabla titulo={`Docentes (${meta.total})`} columnas={['Docente', 'DNI', 'Especialidad', 'Estado']} pie={<Paginacion meta={meta} ruta={RUTA} parametros={actuales} />}>
          {docentes.map((docente) => (
            <tr key={docente.identificador} className="hover:bg-academico-50/50">
              <td className="px-4 py-3">
                <Link href={`${RUTA}/${docente.identificador}`} className="font-semibold text-panel-text hover:text-academico-800 hover:underline">
                  {docente.apellidos}, {docente.nombres}
                </Link>
              </td>
              <td className="px-4 py-3 font-mono text-xs text-panel-muted">{docente.dni}</td>
              <td className="px-4 py-3 text-panel-muted">{docente.especialidad ?? '—'}</td>
              <td className="px-4 py-3">
                <div className="flex flex-wrap gap-1">
                  <Insignia color={colorEstado(docente.estado)}>{etiqueta('estadoActivo', docente.estado)}</Insignia>
                  {docente.eliminado ? <Insignia color="rojo">Eliminado</Insignia> : null}
                </div>
              </td>
            </tr>
          ))}
        </Tabla>
      )}
    </div>
  );
}
