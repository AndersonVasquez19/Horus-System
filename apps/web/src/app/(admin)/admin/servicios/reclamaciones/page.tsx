import { PaginaReclamaciones } from '@/components/compartido/pagina-reclamaciones';

export const metadata = { title: 'Libro de reclamaciones' };

export default function ReclamacionesPage({ searchParams }: { searchParams: Promise<Record<string, string | string[] | undefined>> }) {
  return <PaginaReclamaciones tono="servicios" searchParams={searchParams} />;
}
