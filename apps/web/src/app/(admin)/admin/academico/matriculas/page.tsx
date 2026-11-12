import Link from 'next/link';
import { Suspense } from 'react';
import { AccionesListadoMatriculas } from '@/components/academico/acciones-matricula';
import { EncabezadoPagina } from '@/components/shared/ui/encabezado-pagina';
import { EstadoError, EstadoProhibido, EstadoSinResultados, EstadoVacio } from '@/components/shared/ui/estados';
import { CampoBusqueda, FiltrosListado, SelectorFiltro, parametro } from '@/components/shared/ui/filtros-listado';
import { Insignia, colorEstado } from '@/components/shared/ui/insignia';
import { Paginacion, type MetaListado } from '@/components/shared/ui/paginacion';
import { EsqueletoListado, Tabla } from '@/components/shared/ui/tabla';
import type { MatriculaResumen } from '@/features/academico/tipos';
import { consulta, consultarPanel } from '@/lib/panel/api-servidor';
import { ETIQUETAS, etiqueta, fecha, moneda } from '@/lib/panel/formato';

export const metadata = { title: 'Matrículas · Gestión académica' };

const RUTA = '/admin/academico/matriculas';
const PARAMETROS = ['busqueda', 'estado', 'curso_id', 'eliminados', 'sort', 'page'] as const;
const FILTROS = ['busqueda', 'estado', 'curso_id', 'eliminados'] as const;

type Parametros = Record<string, string | string[] | undefined>;

export default function MatriculasPage({ searchParams }: { searchParams: Promise<Parametros> }) {
  return (
    <Suspense fallback={<EsqueletoListado etiqueta="Cargando matrículas" />}>
      <ListadoMatriculas searchParams={searchParams} />
    </Suspense>
  );
}

async function ListadoMatriculas({ searchParams }: { searchParams: Promise<Parametros> }) {
  const parametros = await searchParams;
  const cadena = consulta(parametros, PARAMETROS);
  const resultado = await consultarPanel<{ data: MatriculaResumen[]; meta: MetaListado }>(`academico/matriculas${cadena}`, `${RUTA}${cadena}`);

  const encabezado = (
    <EncabezadoPagina
      titulo="Matrículas"
      descripcion="Matrículas por estudiante y curso, con su estado y ocupación de cupos."
      migas={[{ etiqueta: 'Inicio', href: '/admin/academico' }, { etiqueta: 'Matrículas' }]}
      // La exportación usa exactamente los filtros del listado (sin orden ni página).
      acciones={<AccionesListadoMatriculas filtros={consulta(parametros, FILTROS)} />}
    />
  );

  if (resultado.estado === 'prohibido') return <>{encabezado}<EstadoProhibido volver="/admin/academico" /></>;
  if (resultado.estado !== 'ok') return <>{encabezado}<EstadoError texto="Inténtalo de nuevo en unos minutos." /></>;

  const { data: matriculas, meta } = resultado.datos;
  const actuales = Object.fromEntries(
    PARAMETROS.filter((nombre) => nombre !== 'page' && parametro(parametros, nombre) !== '').map((nombre) => [nombre, parametro(parametros, nombre)]),
  );
  const hayFiltros = Object.keys(meta.filtros).length > 0;
  const cursoFiltrado = parametro(parametros, 'curso_id');

  return (
    <>
      {encabezado}
      <div className="space-y-4">
        <FiltrosListado tono="academico" ruta={RUTA} hayFiltros={hayFiltros}>
          <CampoBusqueda tono="academico" nombre="busqueda" valor={parametro(parametros, 'busqueda')} marcador="DNI, estudiante, código o curso" />
          <SelectorFiltro tono="academico" nombre="estado" etiqueta="Estado" valor={parametro(parametros, 'estado')} opciones={ETIQUETAS.estadoMatricula} />
          <SelectorFiltro
            tono="academico"
            nombre="sort"
            etiqueta="Ordenar por"
            valor={parametro(parametros, 'sort') || '-fecha'}
            todos={null}
            opciones={{ '-fecha': 'Más recientes', fecha: 'Más antiguas', '-creado': 'Registradas últimamente', estado: 'Estado' }}
          />
          {/^\d{1,18}$/.test(cursoFiltrado) ? <input type="hidden" name="curso_id" value={cursoFiltrado} /> : null}
          <label className="flex items-center gap-2 pb-2 text-sm text-panel-text">
            <input type="checkbox" name="eliminados" value="1" defaultChecked={parametro(parametros, 'eliminados') === '1'} className="h-4 w-4 rounded border-panel-border accent-academico-700" />
            Eliminadas
          </label>
        </FiltrosListado>

        {meta.filtros.curso_id ? (
          <p className="text-sm text-panel-muted">
            Mostrando solo un curso.{' '}
            <Link href={RUTA} className="font-semibold text-academico-700 hover:underline">Ver todos los cursos</Link>
          </p>
        ) : null}

        {matriculas.length === 0 ? (
          hayFiltros ? (
            <EstadoSinResultados titulo="Ninguna matrícula coincide con los filtros" texto="Prueba con otros criterios o limpia los filtros." />
          ) : (
            <EstadoVacio titulo="Todavía no hay matrículas registradas" />
          )
        ) : (
          <Tabla
            titulo={`Matrículas (${meta.total})`}
            columnas={['Estudiante', 'Curso', 'Fecha', { texto: 'Monto', derecha: true }, 'Estado']}
            pie={<Paginacion meta={meta} ruta={RUTA} parametros={actuales} />}
          >
            {matriculas.map((matricula) => (
              <tr key={matricula.id} className="hover:bg-academico-50/50">
                <td className="px-4 py-3">
                  <Link href={`${RUTA}/${matricula.id}`} className="font-semibold text-panel-text hover:text-academico-800 hover:underline">
                    {matricula.estudiante?.nombre ?? 'Estudiante no disponible'}
                  </Link>
                  <p className="font-mono text-xs text-panel-muted">{matricula.estudiante?.dni}</p>
                </td>
                <td className="px-4 py-3">
                  <p className="text-panel-text">{matricula.curso?.nombre ?? '—'}</p>
                  <p className="text-xs text-panel-muted">{matricula.curso?.codigo}</p>
                </td>
                <td className="whitespace-nowrap px-4 py-3 text-panel-muted">{fecha(matricula.fecha)}</td>
                <td className="whitespace-nowrap px-4 py-3 text-right tabular-nums text-panel-muted">{moneda(matricula.monto_pagado)}</td>
                <td className="px-4 py-3">
                  <div className="flex flex-wrap gap-1">
                    <Insignia color={colorEstado(matricula.estado)}>{etiqueta('estadoMatricula', matricula.estado)}</Insignia>
                    {matricula.eliminado ? <Insignia color="rojo">Eliminada</Insignia> : null}
                  </div>
                </td>
              </tr>
            ))}
          </Tabla>
        )}
      </div>
    </>
  );
}
