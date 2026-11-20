import { PaginaAprobaciones } from '@/components/compartido/pagina-aprobaciones';

export const metadata = { title: 'Aprobaciones internas' };

export default function AprobacionesPage({ searchParams }: { searchParams: Promise<Record<string, string | string[] | undefined>> }) {
  return <PaginaAprobaciones tono="academico" searchParams={searchParams} />;
}
