import { PaginaUsuarios } from '@/components/compartido/pagina-usuarios';

export const metadata = { title: 'Usuarios' };

export default function UsuariosPage({ searchParams }: { searchParams: Promise<Record<string, string | string[] | undefined>> }) {
  return <PaginaUsuarios tono="servicios" searchParams={searchParams} />;
}
