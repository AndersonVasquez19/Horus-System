import Link from 'next/link';
import { ArrowLeft, Plus } from 'lucide-react';
import { Suspense } from 'react';
import { AccionesCurso } from '@/components/academico/acciones-curso';
import { BotonNuevaConvocatoria } from '@/components/academico/acciones-convocatoria';
import { HistorialAcademico } from '@/components/academico/historial-academico';
import { PanelPublicacion } from '@/components/academico/panel-publicacion';
import { Alerta } from '@/components/shared/ui/alerta';
import { BotonesGrupoWhatsApp } from '@/components/shared/ui/acciones-contacto';
import { EncabezadoPagina } from '@/components/shared/ui/encabezado-pagina';
import { EstadoError, EstadoProhibido, EstadoSinResultados } from '@/components/shared/ui/estados';
import { Insignia, colorEstado } from '@/components/shared/ui/insignia';
import { Seccion, Tabla } from '@/components/shared/ui/tabla';
import type { CursoDetalle } from '@/features/academico/tipos';
import { consultarPanel } from '@/lib/panel/api-servidor';
import { etiqueta, fecha, moneda, numero } from '@/lib/panel/formato';

export const metadata = { title: 'Detalle del curso · Gestión académica' };

/*
 * Ruta con parámetro dinámico: la Navbar pública del layout raíz (protegido; oculta en el panel) usa
 * `usePathname` fuera de `<Suspense>` y bloquea el prerender de rutas dinámicas. El detalle depende de la sesión,
 * así que se permite que la navegación espere al servidor. Retirar cuando la web pública pase a su route group.
 */
export const instant = false;

const LISTADO = '/admin/academico/cursos';

export default function CursoDetallePage({ params }: { params: Promise<{ id: string }> }) {
  return (
    <Suspense fallback={<div className="h-96 animate-pulse rounded-xl border border-panel-border bg-panel-surface" aria-busy="true" aria-label="Cargando el curso" />}>
      <Detalle params={params} />
    </Suspense>
  );
}

async function Detalle({ params }: { params: Promise<{ id: string }> }) {
  const { id } = await params;
  const volver = (
    <Link href={LISTADO} className="inline-flex items-center gap-1 text-sm font-semibold text-academico-700 hover:underline">
      <ArrowLeft className="h-4 w-4" aria-hidden="true" /> Volver a cursos
    </Link>
  );

  if (!/^\d{1,10}$/.test(id)) {
    return <EstadoSinResultados titulo="El curso no existe" accion={volver} />;
  }

  const resultado = await consultarPanel<{ data: CursoDetalle }>(`academico/cursos/${id}`, `${LISTADO}/${id}`);
  if (resultado.estado === 'prohibido') return <EstadoProhibido volver="/admin/academico" />;
  if (resultado.estado === 'no_encontrado') return <EstadoSinResultados titulo="El curso no existe" accion={volver} />;
  if (resultado.estado !== 'ok') return <EstadoError texto="Inténtalo de nuevo en unos minutos." accion={volver} />;

  const curso = resultado.datos.data;
  const ocupados = curso.cupos_maximos - curso.cupos_disponibles;

  return (
    <>
      <EncabezadoPagina
        titulo={curso.nombre}
        descripcion={curso.codigo}
        migas={[
          { etiqueta: 'Inicio', href: '/admin/academico' },
          { etiqueta: 'Cursos', href: LISTADO },
          { etiqueta: curso.codigo },
        ]}
        acciones={
          <>
            <div className="flex flex-wrap items-center gap-1">
              <Insignia color={colorEstado(curso.estado)}>{etiqueta('estadoCurso', curso.estado)}</Insignia>
              {curso.eliminado ? <Insignia color="rojo">Archivado</Insignia> : null}
            </div>
            {!curso.eliminado && curso.estado === 'activo' ? (
              <BotonNuevaConvocatoria cursoInicial={{ id: curso.id, codigo: curso.codigo, nombre: curso.nombre }} />
            ) : null}
            <AccionesCurso curso={curso} />
          </>
        }
      />

      {curso.eliminado ? (
        <Alerta tipo="aviso" className="mb-6">Este curso está archivado: no aparece en los listados ni admite nuevas matrículas.</Alerta>
      ) : null}

      <div className="grid gap-6 xl:grid-cols-3">
        <section aria-labelledby="titulo-datos" className="rounded-xl border border-panel-border bg-panel-surface p-5 shadow-sm xl:col-span-1">
          <h2 id="titulo-datos" className="font-heading text-base font-semibold text-panel-text">Datos del curso</h2>
          <dl className="mt-4 space-y-3 text-sm">
            {[
              ['Categoría', etiqueta('categoria', curso.categoria)],
              ['Modalidad', etiqueta('modalidad', curso.modalidad)],
              ['Duración', `${numero(curso.duracion_horas)} horas`],
              ['Precio', moneda(curso.precio)],
              ['Horario', curso.horario || '—'],
              ['Cupos', `${numero(ocupados)} ocupados · ${numero(curso.cupos_disponibles)} libres · ${numero(curso.cupos_maximos)} en total`],
              ['Registrado', fecha(curso.creado_en)],
              ['Última actualización', fecha(curso.actualizado_en)],
            ].map(([termino, definicion]) => (
              <div key={termino} className="grid grid-cols-3 gap-2">
                <dt className="text-panel-muted">{termino}</dt>
                <dd className="col-span-2 font-medium text-panel-text">{definicion}</dd>
              </div>
            ))}
          </dl>
          {curso.enlace_grupo_whatsapp ? (
            <div className="mt-4 border-t border-panel-border pt-4">
              <p className="mb-2 text-xs font-medium text-panel-muted">Grupo de WhatsApp</p>
              <BotonesGrupoWhatsApp enlace={curso.enlace_grupo_whatsapp} />
            </div>
          ) : null}
          {curso.descripcion ? (
            <div className="mt-5 border-t border-panel-border pt-4">
              <h3 className="text-sm font-semibold text-panel-text">Descripción</h3>
              <p className="mt-1 whitespace-pre-line text-sm text-panel-muted">{curso.descripcion}</p>
            </div>
          ) : null}
        </section>

        <section aria-labelledby="titulo-estudiantes" className="overflow-hidden rounded-xl border border-panel-border bg-panel-surface shadow-sm xl:col-span-2">
          <div className="px-5 pt-5">
            <h2 id="titulo-estudiantes" className="font-heading text-base font-semibold text-panel-text">Estudiantes matriculados</h2>
            <p className="text-xs text-panel-muted">{numero(curso.matriculas_activas)} matrículas vigentes (sin anuladas ni eliminadas)</p>
            <Link href={`/admin/academico/matriculas?curso_id=${curso.id}`} className="mt-1 inline-block text-xs font-semibold text-academico-700 hover:underline">
              Ver todas las matrículas del curso
            </Link>
            <Link href={`/admin/academico/convocatorias?curso_id=${curso.id}`} className="ml-4 mt-1 inline-block text-xs font-semibold text-academico-700 hover:underline">
              Ver ediciones del curso
            </Link>
            {!curso.eliminado && curso.estado === 'activo' && curso.cupos_disponibles > 0 ? (
              <Link href={`/admin/academico/matriculas/nueva?curso_id=${curso.id}`} className="ml-4 mt-1 inline-flex items-center gap-1 text-xs font-semibold text-academico-700 hover:underline">
                <Plus className="h-3.5 w-3.5" aria-hidden="true" /> Matricular en este curso
              </Link>
            ) : null}
          </div>
          {curso.estudiantes.length === 0 ? (
            <p className="px-5 py-12 text-center text-sm text-panel-muted">Este curso todavía no tiene estudiantes matriculados.</p>
          ) : (
            <Tabla plana titulo="Estudiantes matriculados en el curso" columnas={['Estudiante', 'Matrícula', 'Fecha']}>
                  {curso.estudiantes.map((estudiante) => (
                    <tr key={estudiante.matricula_id}>
                      <td className="px-4 py-3 font-medium text-panel-text">
                        <Link href={`/admin/academico/estudiantes/${estudiante.id}`} className="hover:text-academico-800 hover:underline">
                          {estudiante.nombre}
                        </Link>
                      </td>
                      <td className="px-4 py-3">
                        <Link href={`/admin/academico/matriculas/${estudiante.matricula_id}`} className="inline-flex items-center gap-2 hover:underline">
                          <Insignia color={colorEstado(estudiante.estado_matricula)}>{etiqueta('estadoMatricula', estudiante.estado_matricula)}</Insignia>
                          <span className="text-xs text-academico-700">#{estudiante.matricula_id}</span>
                        </Link>
                      </td>
                      <td className="whitespace-nowrap px-4 py-3 text-panel-muted">{fecha(estudiante.fecha_matricula)}</td>
                    </tr>
                  ))}

</Tabla>
          )}
        </section>

        <div className="space-y-6 xl:col-span-3">
          <PanelPublicacion curso={curso} />
          <Seccion id="titulo-historial" titulo="Historial" descripcion="Operaciones registradas en la auditoría.">
            <HistorialAcademico entidad="curso" eventos={curso.historial} />
          </Seccion>
        </div>
      </div>
    </>
  );
}
