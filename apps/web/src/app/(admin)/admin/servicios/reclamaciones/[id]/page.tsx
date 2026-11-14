import { PaginaReclamacion } from '@/components/compartido/pagina-reclamacion';

export const metadata = { title: 'Reclamación' };

// La Navbar pública del layout raíz bloquea el prerender de rutas dinámicas (ver cursos/[id]).
export const instant = false;

export default function ReclamacionPage({ params }: { params: Promise<{ id: string }> }) {
  return <PaginaReclamacion tono="servicios" params={params} />;
}
