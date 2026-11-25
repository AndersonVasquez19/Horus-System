import { redirect } from 'next/navigation';
import { Suspense } from 'react';

export const metadata = { title: 'Editar matrícula · Gestión académica' };

// Ver la nota de cursos/[id]: la Navbar pública del layout raíz bloquea el prerender de rutas dinámicas.
export const instant = false;

/**
 * Redirige enlaces o marcadores directos a `/matriculas/[id]/editar` hacia la ficha de la matrícula
 * con el modal de edición abierto.
 */
export default function EditarMatriculaPage({ params }: { params: Promise<{ id: string }> }) {
  return (
    <Suspense fallback={null}>
      <RedirigirAEdicion params={params} />
    </Suspense>
  );
}

async function RedirigirAEdicion({ params }: { params: Promise<{ id: string }> }): Promise<never> {
  const { id } = await params;
  if (!/^\d{1,10}$/.test(id)) {
    redirect('/admin/academico/matriculas');
  }
  redirect(`/admin/academico/matriculas/${id}?editar=1`);
}
