import type { Metadata } from 'next';
import { EstadoNoEncontradoPanel } from '@/components/shared/ui/estados';

export const metadata: Metadata = { title: 'Página no encontrada · Panel administrativo' };

export default function AdminNotFound() {
  return (
    <div className="flex flex-1 items-center justify-center p-6">
      <div className="w-full max-w-lg">
        <EstadoNoEncontradoPanel volver="/admin/academico" />
      </div>
    </div>
  );
}
