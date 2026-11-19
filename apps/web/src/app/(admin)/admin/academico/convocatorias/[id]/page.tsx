import Link from 'next/link';
import { ArrowLeft } from 'lucide-react';
import { Suspense } from 'react';
import { AccionesConvocatoria, EditorHorarios } from '@/components/academico/acciones-convocatoria';
import { PanelCalificaciones } from '@/components/academico/acciones-calificaciones';
import { BotonMatricularConPago } from '@/components/academico/matricular-con-pago';
import { PanelDocentesEdicion, PanelInfraestructuraEdicion } from '@/components/academico/paneles-edicion';
import { ResumenAsistenciaEdicion } from '@/components/academico/paneles-asistencia';
import { PanelSesionesEdicion } from '@/components/academico/paneles-sesiones';
import { EncabezadoPagina } from '@/components/shared/ui/encabezado-pagina';
import { EstadoError, EstadoProhibido, EstadoSinResultados } from '@/components/shared/ui/estados';
import { Insignia, colorEstado } from '@/components/shared/ui/insignia';
import { EsqueletoFicha, ListaDatos, Seccion } from '@/components/shared/ui/tabla';
import { TRANSICIONES_CONVOCATORIA, type ActaCalificaciones, type AulaResumen, type ConvocatoriaDetalle, type DocenteAsignado, type DocenteResumen, type PlataformaResumen, type ResumenAsistenciaFila, type SedeResumen, type SesionClase } from '@/features/academico/tipos';
import { consultarPanel } from '@/lib/panel/api-servidor';
import { etiqueta, fecha } from '@/lib/panel/formato';

export const metadata = { title: 'Convocatoria · Gestión académica' };

// Ver la nota de academico/cursos/[id]: la Navbar pública del layout raíz bloquea el prerender de rutas dinámicas.
export const instant = false;

const LISTADO = '/admin/academico/convocatorias';

export default function ConvocatoriaDetallePage({ params }: { params: Promise<{ id: string }> }) {
  return (
    <Suspense fallback={<EsqueletoFicha etiqueta="Cargando la convocatoria" />}>
      <Ficha params={params} />
    </Suspense>
  );
}

async function Ficha({ params }: { params: Promise<{ id: string }> }) {
  const { id } = await params;
  const volver = (
    <Link href={LISTADO} className="inline-flex items-center gap-1 text-sm font-semibold text-academico-700 hover:underline">
      <ArrowLeft className="h-4 w-4" aria-hidden="true" /> Volver a convocatorias
    </Link>
  );

  if (!/^[0-9a-f-]{36}$/i.test(id)) return <EstadoSinResultados titulo="La convocatoria no existe" accion={volver} />;

  const resultado = await consultarPanel<{ data: ConvocatoriaDetalle }>(`academico/convocatorias/${id}`, `${LISTADO}/${id}`);
  if (resultado.estado === 'prohibido') return <EstadoProhibido volver="/admin/academico" />;
  if (resultado.estado === 'no_encontrado') return <EstadoSinResultados titulo="La convocatoria no existe" accion={volver} />;
  if (resultado.estado !== 'ok') return <EstadoError texto="Inténtalo de nuevo en unos minutos." accion={volver} />;

  const c = resultado.datos.data;
  const transiciones = TRANSICIONES_CONVOCATORIA[c.estado];

  return (
    <>
      <EncabezadoPagina
        titulo={c.nombre ?? c.codigo}
        descripcion={c.curso ? `${c.codigo} · ${c.curso.nombre}` : c.codigo}
        migas={[{ etiqueta: 'Inicio', href: '/admin/academico' }, { etiqueta: 'Convocatorias', href: LISTADO }, { etiqueta: c.codigo }]}
        acciones={
          <div className="flex flex-wrap items-center gap-2">
            <BotonMatricularConPago convocatoria={c.identificador} disponible={c.estado === 'abierta' || c.estado === 'en_curso'} />
            <AccionesConvocatoria convocatoria={c} transiciones={transiciones} />
          </div>
        }
      />

      <div className="grid gap-6 xl:grid-cols-3">
        <Seccion id="titulo-datos" titulo="Datos" acciones={<Insignia color={colorEstado(c.estado)}>{etiqueta('estadoConvocatoria', c.estado)}</Insignia>}>
          <ListaDatos
            filas={[
              ['Código', c.codigo],
              [
                'Curso',
                c.curso ? <Link href={`/admin/academico/cursos/${c.curso.id}`} className="font-semibold text-academico-700 hover:underline">{c.curso.nombre}</Link> : '—',
              ],
              ['Modalidad', etiqueta('modalidad', c.modalidad)],
              ['Inicio', fecha(c.fecha_inicio)],
              ['Fin', fecha(c.fecha_fin)],
              ['Cupos', `${c.cupos.disponibles} disponibles de ${c.cupos.maximos} (${c.cupos.ocupados} ocupados)`],
              ['Registrada', fecha(c.creado_en)],
            ]}
          />
        </Seccion>

        <div className="space-y-6 xl:col-span-2">
          <Seccion id="titulo-horario" titulo="Horario" descripcion="Los bloques se guardan juntos; dos bloques del mismo día no pueden solaparse.">
            <EditorHorarios convocatoria={c} editable={transiciones.length > 0} />
          </Seccion>
          <Suspense fallback={<div className="h-40 animate-pulse rounded-xl border border-panel-border bg-panel-surface" aria-busy="true" aria-label="Cargando infraestructura y docentes" />}>
            <InfraestructuraYDocentes convocatoria={c} editable={transiciones.length > 0} />
          </Suspense>
          <Suspense fallback={<div className="h-40 animate-pulse rounded-xl border border-panel-border bg-panel-surface" aria-busy="true" aria-label="Cargando sesiones" />}>
            <SesionesDeLaEdicion convocatoria={c} editable={transiciones.length > 0} />
          </Suspense>
          <Suspense fallback={<div className="h-40 animate-pulse rounded-xl border border-panel-border bg-panel-surface" aria-busy="true" aria-label="Cargando asistencia" />}>
            <AsistenciaDeLaEdicion convocatoria={c} />
          </Suspense>
        </div>
      </div>

      <Suspense fallback={<div className="mt-6 h-40 animate-pulse rounded-xl border border-panel-border bg-panel-surface" aria-busy="true" aria-label="Cargando calificaciones" />}>
        <Calificaciones identificador={id} codigo={c.codigo} />
      </Suspense>
    </>
  );
}

/**
 * Infraestructura y docentes de la edición. Las listas para elegir (sedes, aulas, plataformas y docentes activos, hasta 100
 * de cada una) se piden en el servidor; si la persona no puede verlas, su bloque queda de solo lectura. Laravel vuelve a
 * validar cada elección al guardar.
 */
async function InfraestructuraYDocentes({ convocatoria: c, editable }: { convocatoria: ConvocatoriaDetalle; editable: boolean }) {
  const volverA = `${LISTADO}/${c.identificador}`;
  const [sedes, aulas, plataformas, docentes, asignados] = await Promise.all([
    consultarPanel<{ data: SedeResumen[] }>('academico/sedes?estado=activo&per_page=100&sort=nombre', volverA),
    consultarPanel<{ data: AulaResumen[] }>('academico/aulas?estado=activo&per_page=100&sort=nombre', volverA),
    consultarPanel<{ data: PlataformaResumen[] }>('academico/plataformas-virtuales?estado=activo&per_page=100&sort=nombre', volverA),
    consultarPanel<{ data: DocenteResumen[] }>('academico/docentes?estado=activo&per_page=100&sort=apellidos', volverA),
    consultarPanel<{ data: { docentes: DocenteAsignado[] } }>(`academico/convocatorias/${c.identificador}/docentes`, volverA),
  ]);
  const lista = <T,>(r: { estado: string; datos?: { data: T[] } }): T[] => (r.estado === 'ok' && r.datos ? r.datos.data : []);

  return (
    <>
      <Seccion id="titulo-infraestructura" titulo="Infraestructura" descripcion="Dónde se dicta la edición: sede y aula (presencial) o plataforma virtual (virtual).">
        <PanelInfraestructuraEdicion
          convocatoria={c}
          editable={editable}
          sedes={lista(sedes).map((s) => ({ identificador: s.identificador, codigo: s.codigo, nombre: s.nombre }))}
          aulas={lista(aulas)}
          plataformas={lista(plataformas)}
        />
      </Seccion>
      <Seccion id="titulo-docentes" titulo="Docentes" descripcion="Un docente principal como máximo; los demás son de apoyo.">
        <PanelDocentesEdicion
          convocatoriaId={c.identificador}
          revision={c.revision}
          editable={editable}
          asignados={asignados.estado === 'ok' ? asignados.datos.data.docentes : null}
          docentes={lista(docentes)}
        />
      </Seccion>
    </>
  );
}

/** Sesiones de clase de la edición (hasta 100). El enlace virtual nunca se pide aquí: se consulta aparte, con permiso. */
async function SesionesDeLaEdicion({ convocatoria: c, editable }: { convocatoria: ConvocatoriaDetalle; editable: boolean }) {
  const volverA = `${LISTADO}/${c.identificador}`;
  const [sesiones, asignados] = await Promise.all([
    consultarPanel<{ data: SesionClase[]; meta: { total: number } }>(`academico/convocatorias/${c.identificador}/sesiones?per_page=100&sort=numero`, volverA),
    consultarPanel<{ data: { docentes: DocenteAsignado[] } }>(`academico/convocatorias/${c.identificador}/docentes`, volverA),
  ]);
  return (
    <Seccion id="titulo-sesiones" titulo="Sesiones de clase" descripcion="Calendario de clases de la edición; no se permiten cruces de aula ni de docente.">
      <PanelSesionesEdicion
        convocatoriaId={c.identificador}
        modalidad={c.modalidad}
        sesiones={sesiones.estado === 'ok' ? sesiones.datos.data : null}
        total={sesiones.estado === 'ok' ? sesiones.datos.meta.total : 0}
        docentes={asignados.estado === 'ok' ? asignados.datos.data.docentes.filter((d) => d.estado === 'activo') : []}
        editable={editable}
      />
    </Seccion>
  );
}

/** Resumen de asistencia: solo se muestra a quien tiene `asistencias.ver`. */
async function AsistenciaDeLaEdicion({ convocatoria: c }: { convocatoria: ConvocatoriaDetalle }) {
  const resultado = await consultarPanel<{ data: { sesiones_realizadas: number; matriculas: ResumenAsistenciaFila[] } }>(
    `academico/convocatorias/${c.identificador}/asistencias/resumen`,
    `${LISTADO}/${c.identificador}`,
  );
  if (resultado.estado !== 'ok') return null;
  return (
    <Seccion id="titulo-asistencia" titulo="Asistencia" descripcion="Porcentaje por matrícula sobre las sesiones realizadas.">
      <ResumenAsistenciaEdicion convocatoriaId={c.identificador} sesionesRealizadas={resultado.datos.data.sesiones_realizadas} filas={resultado.datos.data.matriculas} />
    </Seccion>
  );
}

/** Notas y acta: solo se muestra a quien tiene `calificaciones.ver` (Laravel responde 403 al resto). */
async function Calificaciones({ identificador, codigo }: { identificador: string; codigo: string }) {
  const resultado = await consultarPanel<{ data: ActaCalificaciones }>(`academico/convocatorias/${identificador}/calificaciones`, `${LISTADO}/${identificador}#calificaciones`);
  if (resultado.estado === 'prohibido') return null;
  if (resultado.estado !== 'ok') return <EstadoError texto="No se pudieron cargar las calificaciones. Inténtalo de nuevo en unos minutos." />;

  return (
    <div className="mt-6">
      <Seccion id="calificaciones" titulo="Calificaciones" descripcion={`Evaluaciones, notas y acta de ${codigo}. Las notas son datos personales: no se comparten fuera del panel.`}>
        <PanelCalificaciones acta={resultado.datos.data} />
      </Seccion>
    </div>
  );
}
