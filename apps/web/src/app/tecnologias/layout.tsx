import { notFound } from 'next/navigation';
import { TECNOLOGIAS_VISIBLES } from '@/lib/funciones';

/** Área «Tecnologías» oculta: con la bandera apagada todas sus rutas responden 404 (ver `@/lib/funciones`). */
export default function TecnologiasLayout({ children }: { children: React.ReactNode }) {
  if (!TECNOLOGIAS_VISIBLES) notFound();
  return children;
}
