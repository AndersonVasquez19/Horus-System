import Link from 'next/link';
import { ArrowLeft } from 'lucide-react';
import { Suspense } from 'react';
import { AccionesConvocatoria, EditorHorarios } from '@/components/academico/acciones-convocatoria';
import { PanelCalificaciones } from '@/components/academico/acciones-calificaciones';
import { EncabezadoPagina } from '@/components/shared/ui/encabezado-pagina';
import { EstadoError, EstadoProhibido, EstadoSinResultados } from '@/components/shared/ui/estados';
import { Insignia, colorEstado } from '@/components/shared/ui/insignia';
import { EsqueletoFicha, ListaDatos, Seccion } from '@/components/shared/ui/tabla';
import { TRANSICIONES_CONVOCATORIA, type ActaCalificaciones, type ConvocatoriaDetalle } from '@/features/academico/tipos';
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
        acciones={<AccionesConvocatoria convocatoria={c} transiciones={transiciones} />}
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
        </div>
      </div>

      <Suspense fallback={<div className="mt-6 h-40 animate-pulse rounded-xl border border-panel-border bg-panel-surface" aria-busy="true" aria-label="Cargando calificaciones" />}>
        <Calificaciones identificador={id} codigo={c.codigo} />
      </Suspense>
    </>
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
