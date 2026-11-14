import { PaginaAuditoria } from '@/components/compartido/pagina-auditoria';

export const metadata = { title: 'Auditoría' };

export default function AuditoriaPage({ searchParams }: { searchParams: Promise<Record<string, string | string[] | undefined>> }) {
  return <PaginaAuditoria tono="academico" searchParams={searchParams} />;
}
