'use client';

import { ErrorRutaPanel } from '@/components/shared/ui/estado-ruta-panel';

export default function AcademicoError({ error, retry, reset }: { error: unknown; retry?: () => void; reset?: () => void }) {
  return <ErrorRutaPanel error={error} reintentar={retry ?? reset} volver="/admin/academico" />;
}
