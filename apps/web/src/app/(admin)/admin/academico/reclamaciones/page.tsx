import { PaginaReclamaciones } from '@/components/compartido/pagina-reclamaciones';
import { RECLAMACIONES_EN_PANEL } from '@/lib/funciones';

export const metadata = { title: RECLAMACIONES_EN_PANEL ? 'Libro de reclamaciones' : 'Mensajes de contacto' };

export default function ReclamacionesPage({ searchParams }: { searchParams: Promise<Record<string, string | string[] | undefined>> }) {
  return <PaginaReclamaciones tono="academico" searchParams={searchParams} />;
}
