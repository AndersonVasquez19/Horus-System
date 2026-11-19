'use client';

import { ErrorRutaPanel } from '@/components/shared/ui/estado-ruta-panel';

export default function AdminError({ error, retry, reset }: { error: unknown; retry?: () => void; reset?: () => void }) {
  return (
    <div className="flex flex-1 items-center justify-center p-6">
      <ErrorRutaPanel error={error} reintentar={retry ?? reset} volver="/admin/academico" />
    </div>
  );
}
