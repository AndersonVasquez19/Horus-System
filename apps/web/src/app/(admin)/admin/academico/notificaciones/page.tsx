import { PaginaNotificaciones } from '@/components/compartido/pagina-notificaciones';

export const metadata = { title: 'Notificaciones' };

export default function NotificacionesPage({ searchParams }: { searchParams: Promise<Record<string, string | string[] | undefined>> }) {
  return <PaginaNotificaciones tono="academico" searchParams={searchParams} />;
}
