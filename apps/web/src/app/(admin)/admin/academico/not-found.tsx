import type { Metadata } from 'next';
import { EstadoNoEncontradoPanel } from '@/components/shared/ui/estados';

export const metadata: Metadata = { title: 'Página no encontrada · Gestión académica' };

export default function AcademicoNotFound() {
  return <EstadoNoEncontradoPanel volver="/admin/academico" />;
}
