import { PaginaHojaReclamacion } from '@/components/compartido/pagina-hoja-reclamacion';

export const metadata = { title: 'Hoja de reclamación' };

export const instant = false;

export default function HojaReclamacionPage({ params }: { params: Promise<{ id: string }> }) {
  return <PaginaHojaReclamacion tono="academico" params={params} />;
}
