import { PaginaReclamacion } from '@/components/compartido/pagina-reclamacion';
import { RECLAMACIONES_EN_PANEL } from '@/lib/funciones';
import { redirect } from 'next/navigation';

export const metadata = { title: 'Reclamación' };

// La Navbar pública del layout raíz bloquea el prerender de rutas dinámicas (ver cursos/[id]).
export const instant = false;

export default function ReclamacionPage({ params }: { params: Promise<{ id: string }> }) {
  if (!RECLAMACIONES_EN_PANEL) redirect('/admin/academico/reclamaciones');
  return <PaginaReclamacion tono="academico" params={params} />;
}
