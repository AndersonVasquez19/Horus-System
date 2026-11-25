import Link from 'next/link';
import { Suspense } from 'react';
import {
  AccionesListadoMatriculas,
  BotonGenerarCobro,
  BotonNuevaMatriculaVacio,
  ETIQUETAS_OPERATIVAS,
  MenuFilaMatricula,
} from '@/components/academico/acciones-matricula';
import { FiltrosMatriculas, SelectorPorPaginaPie, type FiltrosMatriculasValores } from '@/components/academico/filtros-matriculas';
import { CeldaTelefono } from '@/components/shared/ui/acciones-celda';
import { EncabezadoPagina } from '@/components/shared/ui/encabezado-pagina';
import { EstadoError, EstadoProhibido, EstadoSinResultados, EstadoVacio } from '@/components/shared/ui/estados';
import { parametro } from '@/components/shared/ui/filtros-listado';
import { Insignia, colorEstado, type ColorInsignia } from '@/components/shared/ui/insignia';
import { Paginacion, type MetaListado } from '@/components/shared/ui/paginacion';
import { EsqueletoListado, Tabla } from '@/components/shared/ui/tabla';
import type { MatriculaResumen } from '@/features/academico/tipos';
import { consulta, consultarPanel } from '@/lib/panel/api-servidor';
import { mensajeBienvenida } from '@/lib/panel/contacto';
import { etiqueta, fecha, moneda } from '@/lib/panel/formato';

export const metadata = { title: 'Matrículas · Gestión académica' };

const RUTA = '/admin/academico/matriculas';
const PARAMETROS = ['busqueda', 'estado', 'curso_id', 'convocatoria', 'estado_cobro', 'eliminados', 'sort', 'per_page', 'page'] as const;
const FILTROS = ['busqueda', 'estado', 'curso_id', 'convocatoria', 'estado_cobro', 'eliminados'] as const;

type Parametros = Record<string, string | string[] | undefined>;
interface MetaMatriculas extends MetaListado {
  convocatorias?: { identificador: string; codigo: string; nombre: string | null }[];
}

const COLOR_COBRO_BADGE: Record<string, { texto: string; color: ColorInsignia }> = {
  pendiente: { texto: 'Pendiente', color: 'ambar' },
  parcial: { texto: 'Parcial', color: 'azul' },
  pagada: { texto: 'Pagado', color: 'verde' },
  anulada: { texto: 'Anulada', color: 'rojo' },
  sin_obligacion: { texto: 'Sin obligación', color: 'gris' },
};

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
  const resultado = await consultarPanel<{ data: MatriculaResumen[]; meta: MetaMatriculas }>(`academico/matriculas${cadena}`, `${RUTA}${cadena}`);

  const abrirNuevaInicial = parametro(parametros, 'nueva') === '1';
  const estudianteInicialId = parametro(parametros, 'estudiante_id') || null;
  const cursoInicialId = parametro(parametros, 'pre_curso_id') || null;

  const encabezado = (
    <EncabezadoPagina
      titulo="Matrículas"
      descripcion="Registro de inscripciones por estudiante y edición, con seguimiento de pago y estado."
      migas={[{ etiqueta: 'Inicio', href: '/admin/academico' }, { etiqueta: 'Matrículas' }]}
      // La exportación usa exactamente los filtros del listado (sin orden ni página).
      acciones={
        <AccionesListadoMatriculas
          filtros={consulta(parametros, FILTROS)}
          abrirNuevaInicial={abrirNuevaInicial}
          estudianteInicialId={estudianteInicialId}
          cursoInicialId={cursoInicialId}
        />
      }
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
  const cursoFiltradoNombre = cursoFiltrado !== '' ? (matriculas[0]?.curso?.nombre ?? null) : null;

  const valoresFiltros: FiltrosMatriculasValores = {
    busqueda: parametro(parametros, 'busqueda'),
    estado: parametro(parametros, 'estado'),
    convocatoria: parametro(parametros, 'convocatoria'),
    estado_cobro: parametro(parametros, 'estado_cobro'),
    curso_id: /^\d{1,18}$/.test(cursoFiltrado) ? cursoFiltrado : '',
    eliminados: parametro(parametros, 'eliminados') === '1' ? '1' : '',
    sort: parametro(parametros, 'sort') || '-fecha',
    per_page: parametro(parametros, 'per_page') || '20',
  };

  return (
    <>
      {encabezado}
      <div className="space-y-4">
        <FiltrosMatriculas
          ruta={RUTA}
          valores={valoresFiltros}
          convocatorias={meta.convocatorias ?? []}
          cursoFiltradoNombre={cursoFiltradoNombre}
        />

        {matriculas.length === 0 ? (
          hayFiltros ? (
            <EstadoSinResultados
              titulo="Ninguna matrícula coincide con los filtros"
              texto="Prueba con otros criterios o limpia los filtros para ver todo el registro."
              accion={
                <Link
                  href={RUTA}
                  className="inline-flex min-h-10 items-center justify-center rounded-lg border border-panel-border bg-panel-surface px-4 py-2 text-sm font-semibold text-panel-text transition hover:bg-academico-50"
                >
                  Limpiar filtros
                </Link>
              }
            />
          ) : (
            <EstadoVacio
              titulo="Todavía no hay matrículas registradas"
              texto="Registra una matrícula para reservar cupo en un curso o edición y llevar su seguimiento de pago."
              accion={<BotonNuevaMatriculaVacio />}
            />
          )
        ) : (
          <Tabla
            accionesFijas
            titulo={`Matrículas (${meta.total})`}
            columnas={[
              'Estudiante',
              'Curso y edición',
              'Contacto',
              { texto: 'Fecha', orden: 'fecha' },
              'Pago',
              { texto: 'Estado', orden: 'estado' },
              { texto: 'Acciones', derecha: true },
            ]}
            ordenActual={meta.orden}
            rutaOrden={RUTA}
            parametrosOrden={actuales}
            pie={
              <div className="flex flex-col gap-3 border-t border-panel-border px-4 py-3 sm:flex-row sm:items-center sm:justify-between print:hidden">
                <SelectorPorPaginaPie ruta={RUTA} valores={valoresFiltros} />
                <div className="sm:flex-1">
                  <Paginacion meta={meta} ruta={RUTA} parametros={actuales} suelta />
                </div>
              </div>
            }
          >
            {matriculas.map((matricula) => {
              const est = matricula.estudiante;
              const nombreEst = est?.nombre ?? 'Estudiante';
              const cursoNombre = matricula.curso?.nombre ?? 'nuestro curso';
              const saludoWa = mensajeBienvenida({
                nombre: nombreEst,
                curso: cursoNombre,
                edicion: matricula.convocatoria?.codigo,
                enlaceGrupo: matricula.curso?.enlace_grupo_whatsapp,
              });
              const cobro = COLOR_COBRO_BADGE[matricula.estado_cobro ?? 'sin_obligacion'] ?? COLOR_COBRO_BADGE.sin_obligacion;
              const etiquetaOp = matricula.etiqueta ? (ETIQUETAS_OPERATIVAS[matricula.etiqueta] ?? matricula.etiqueta) : null;

              return (
                <tr key={matricula.id} className="hover:bg-academico-50/50">
                  <td className="px-4 py-3">
                    <Link href={`${RUTA}/${matricula.id}`} className="font-semibold text-panel-text hover:text-academico-800 hover:underline">
                      {matricula.estudiante?.nombre ?? 'Estudiante no disponible'}
                    </Link>
                    <div className="mt-0.5 flex flex-wrap items-center gap-1.5">
                      <span className="font-mono text-xs tabular-nums text-panel-muted">
                        #{matricula.id}
                        {matricula.estudiante?.dni ? ` · ${matricula.estudiante.dni}` : ''}
                      </span>
                      {etiquetaOp ? <Insignia color="azul">{etiquetaOp}</Insignia> : null}
                    </div>
                  </td>

                  <td className="px-4 py-3">
                    <p className="font-medium text-panel-text">{matricula.curso?.nombre ?? '—'}</p>
                    <div className="mt-0.5 flex flex-wrap items-center gap-1.5 text-xs text-panel-muted">
                      {matricula.curso?.codigo ? <span className="font-mono">{matricula.curso.codigo}</span> : null}
                      {matricula.convocatoria ? (
                        <>
                          {matricula.curso?.codigo ? <span aria-hidden="true">·</span> : null}
                          <Link
                            href={`/admin/academico/convocatorias/${matricula.convocatoria.identificador}`}
                            className="font-mono font-medium text-academico-700 hover:underline"
                          >
                            {matricula.convocatoria.codigo}
                          </Link>
                        </>
                      ) : (
                        <>
                          {matricula.curso?.codigo ? <span aria-hidden="true">·</span> : null}
                          <span>Sin edición</span>
                        </>
                      )}
                    </div>
                  </td>

                  <td className="px-4 py-3">
                    <CeldaTelefono telefono={est?.telefono} mensajeWa={saludoWa} />
                  </td>

                  <td className="whitespace-nowrap px-4 py-3 tabular-nums text-panel-muted">{fecha(matricula.fecha)}</td>

                  <td className="px-4 py-3">
                    {matricula.obligacion_identificador ? (
                      <Link href={`/admin/academico/pagos/${matricula.obligacion_identificador}`} className="inline-flex flex-col gap-0.5 hover:underline">
                        {matricula.estado_cobro === 'pagada' ? (
                          <>
                            <span>
                              <Insignia color="verde">Completo</Insignia>
                            </span>
                            <span className="text-xs tabular-nums text-panel-muted">{moneda(matricula.monto_pagado)}</span>
                          </>
                        ) : matricula.saldo_cobro && Number.parseFloat(matricula.saldo_cobro) > 0 ? (
                          <>
                            <span>
                              <Insignia color={matricula.estado_cobro === 'parcial' ? 'azul' : 'ambar'}>
                                Debe {moneda(matricula.saldo_cobro)}
                              </Insignia>
                            </span>
                            <span className="text-xs tabular-nums text-panel-muted">
                              {Number.parseFloat(String(matricula.monto_pagado ?? '0')) > 0
                                ? `Abonó ${moneda(matricula.monto_pagado)}`
                                : 'Sin abonos'}
                            </span>
                          </>
                        ) : (
                          <span>
                            <Insignia color={cobro.color}>{cobro.texto}</Insignia>
                          </span>
                        )}
                      </Link>
                    ) : !matricula.eliminado && matricula.estado !== 'anulada' ? (
                      <BotonGenerarCobro matriculaId={matricula.id} />
                    ) : (
                      <Insignia color="gris">{cobro.texto}</Insignia>
                    )}
                  </td>

                  <td className="px-4 py-3">
                    <div className="flex flex-wrap gap-1">
                      <Insignia color={colorEstado(matricula.estado)}>{etiqueta('estadoMatricula', matricula.estado)}</Insignia>
                      {matricula.eliminado ? <Insignia color="rojo">Eliminada</Insignia> : null}
                    </div>
                  </td>

                  <td className="px-4 py-3 text-right">
                    <MenuFilaMatricula matricula={{ id: matricula.id, estado: matricula.estado, eliminado: Boolean(matricula.eliminado) }} />
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
