import { Suspense } from 'react';
import type { Metadata } from 'next';
import { MatriculaForm } from '@/components/matricula/matricula-form';

export const metadata: Metadata = {
  title: 'Solicita tu matrícula',
  description: 'Solicita información y pre-inscripción a los cursos de Horus Group SRL.',
};

export default function SolicitarMatriculaPage() {
  return (
    <div className="pt-28 pb-20">
      <div className="mx-auto max-w-3xl px-4 py-12 sm:px-6 lg:px-8">
        <Suspense fallback={<div className="py-20 text-center text-gold">Cargando formulario…</div>}>
          <MatriculaForm />
        </Suspense>
      </div>
    </div>
  );
}
