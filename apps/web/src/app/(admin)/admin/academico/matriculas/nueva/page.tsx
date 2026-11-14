import { Suspense } from 'react';
import { FormularioMatricula } from '@/components/academico/formulario-matricula';
import { EncabezadoPagina } from '@/components/shared/ui/encabezado-pagina';
import type { CursoDetalle, EstudianteDetalle } from '@/features/academico/tipos';
import { consultarPanel } from '@/lib/panel/api-servidor';

export const metadata = { title: 'Nueva matrícula · Gestión académica' };

type Parametros = Record<string, string | string[] | undefined>;

export default function NuevaMatriculaPage({ searchParams }: { searchParams: Promise<Parametros> }) {
  return (
    <>
      <EncabezadoPagina
        titulo="Nueva matrícula"
        descripcion="El cupo se reserva al guardar, después de comprobar disponibilidad y duplicados."
        migas={[
          { etiqueta: 'Inicio', href: '/admin/academico' },
          { etiqueta: 'Matrículas', href: '/admin/academico/matriculas' },
          { etiqueta: 'Nueva' },
        ]}
      />
      <Suspense fallback={<div className="h-96 animate-pulse rounded-xl border border-panel-border bg-panel-surface" aria-busy="true" aria-label="Cargando el formulario" />}>
        <Formulario searchParams={searchParams} />
      </Suspense>
    </>
  );
}

/**
 * Preselecciona el estudiante de `?estudiante_id=` (activo y no archivado) y el curso de `?curso_id=` (activo, no
 * archivado y con cupos). Un identificador inválido o no elegible se ignora: el formulario empieza vacío, y Laravel
 * vuelve a validar todo al guardar.
 */
async function Formulario({ searchParams }: { searchParams: Promise<Parametros> }) {
  const { estudiante_id: id } = await searchParams;
  const { curso_id: idCurso } = await searchParams;
  let inicial = null;
  let cursoInicial = null;

  if (typeof id === 'string' && /^\d{1,10}$/.test(id)) {
    const resultado = await consultarPanel<{ data: EstudianteDetalle }>(`academico/estudiantes/${id}`, `/admin/academico/matriculas/nueva?estudiante_id=${id}`);
    if (resultado.estado === 'ok' && resultado.datos.data.estado === 'activo' && !resultado.datos.data.eliminado) {
      const estudiante = resultado.datos.data;
      inicial = { id: estudiante.id, texto: `${estudiante.dni} · ${estudiante.nombres} ${estudiante.apellidos}` };
    }
  }

  if (typeof idCurso === 'string' && /^\d{1,10}$/.test(idCurso)) {
    const resultado = await consultarPanel<{ data: CursoDetalle }>(`academico/cursos/${idCurso}`, `/admin/academico/matriculas/nueva?curso_id=${idCurso}`);
    if (resultado.estado === 'ok' && resultado.datos.data.estado === 'activo' && !resultado.datos.data.eliminado && resultado.datos.data.cupos_disponibles > 0) {
      const curso = resultado.datos.data;
      cursoInicial = { id: curso.id, texto: `${curso.codigo} · ${curso.nombre} (${curso.cupos_disponibles} cupos)` };
    }
  }

  // Tras leer searchParams la página ya es dinámica: la hora actual se puede leer en el servidor.
  const hoy = new Intl.DateTimeFormat('en-CA', { timeZone: 'America/Lima' }).format(new Date());

  return <FormularioMatricula estudianteInicial={inicial} cursoInicial={cursoInicial} fechaInicial={hoy} />;
}
