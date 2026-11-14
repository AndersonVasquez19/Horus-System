'use client';

import { Download } from 'lucide-react';
import { useState } from 'react';
import { usePuede } from '@/components/shared/sesion-panel-contexto';
import { useOperacionPanel } from '@/components/shared/use-operacion-panel';
import { useAvisos } from '@/components/shared/ui/avisos';
import { Boton, type TonoUi } from '@/components/shared/ui/boton';

/** Exportación JSON con los filtros vigentes. Laravel la audita y no conserva copia (permiso `auditoria.exportar`). */
export function ExportarAuditoria({ tono, filtros }: { tono: TonoUi; filtros: string }) {
  const puede = usePuede('auditoria.exportar');
  const { descargar } = useOperacionPanel();
  const { mostrar } = useAvisos();
  const [exportando, setExportando] = useState(false);

  if (!puede) return null;

  async function exportar() {
    setExportando(true);
    const resultado = await descargar(`compartido/auditoria/exportar${filtros}`);
    setExportando(false);
    if (resultado.ok) mostrar('exito', 'Exportación generada con los filtros aplicados.');
    else mostrar('error', resultado.error.message);
  }

  return (
    <Boton tono={tono} variante="secundario" icono={<Download className="h-4 w-4" aria-hidden="true" />} cargando={exportando} onClick={exportar}>
      Exportar JSON (filtros actuales)
    </Boton>
  );
}
