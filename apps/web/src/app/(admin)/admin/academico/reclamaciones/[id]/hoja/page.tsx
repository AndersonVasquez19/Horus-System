import { PaginaHojaReclamacion } from '@/components/compartido/pagina-hoja-reclamacion';
import { RECLAMACIONES_EN_PANEL } from '@/lib/funciones';
import { redirect } from 'next/navigation';

export const metadata = { title: 'Hoja de reclamación' };

export const instant = false;

export default function HojaReclamacionPage({ params }: { params: Promise<{ id: string }> }) {
  if (!RECLAMACIONES_EN_PANEL) redirect('/admin/academico/reclamaciones');
  return <PaginaHojaReclamacion tono="academico" params={params} />;
}
