import { redirect } from 'next/navigation';
import { Suspense } from 'react';

export const metadata = { title: 'Nueva matrícula · Gestión académica' };

type Parametros = Record<string, string | string[] | undefined>;

/**
 * Mantiene compatibilidad con todos los enlaces externos hacia `/matriculas/nueva`
 * (`Estudiantes`, `Cursos`, `Inicio` y paleta de comandos `Ctrl+K`), redirigiendo al listado
 * con el modal centrado de nueva matrícula abierto y preseleccionado.
 */
export default function NuevaMatriculaPage({ searchParams }: { searchParams: Promise<Parametros> }) {
  return (
    <Suspense fallback={null}>
      <RedirigirAlModal searchParams={searchParams} />
    </Suspense>
  );
}

async function RedirigirAlModal({ searchParams }: { searchParams: Promise<Parametros> }): Promise<never> {
  const { estudiante_id: idEstudiante, curso_id: idCurso } = await searchParams;
  const destino = new URLSearchParams({ nueva: '1' });

  if (typeof idEstudiante === 'string' && /^\d{1,10}$/.test(idEstudiante)) {
    destino.set('estudiante_id', idEstudiante);
  }
  if (typeof idCurso === 'string' && /^\d{1,10}$/.test(idCurso)) {
    // Se usa `pre_curso_id` para preseleccionar en el modal sin filtrar la tabla detrás.
    destino.set('pre_curso_id', idCurso);
  }

  redirect(`/admin/academico/matriculas?${destino.toString()}`);
}
