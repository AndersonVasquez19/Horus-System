'use client';

import { Download } from 'lucide-react';
import { useState } from 'react';
import { usePuede } from '@/components/shared/sesion-panel-contexto';
import { useOperacionPanel } from '@/components/shared/use-operacion-panel';
import { useAvisos } from '@/components/shared/ui/avisos';
import { Boton } from '@/components/shared/ui/boton';

/** Descarga del ZIP de exportación. Laravel lo audita y no conserva copia (permiso `respaldo.exportar`). */
export function ExportarRespaldo() {
  const puede = usePuede('respaldo.exportar');
  const { descargar } = useOperacionPanel();
  const { mostrar } = useAvisos();
  const [exportando, setExportando] = useState(false);

  if (!puede) {
    return <p className="text-sm text-panel-muted">Tu cuenta puede ver qué incluye la exportación, pero no descargarla.</p>;
  }

  async function exportar() {
    setExportando(true);
    const resultado = await descargar('compartido/respaldo/exportar');
    setExportando(false);
    if (resultado.ok) mostrar('exito', 'Exportación generada. No se conserva ninguna copia en el servidor.');
    else mostrar('error', resultado.error.message);
  }

  return (
    <Boton icono={<Download className="h-4 w-4" aria-hidden="true" />} cargando={exportando} onClick={exportar}>
      {exportando ? 'Generando…' : 'Descargar exportación (ZIP)'}
    </Boton>
  );
}
