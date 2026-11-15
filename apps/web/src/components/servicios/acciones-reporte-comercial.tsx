'use client';

import { FileSpreadsheet } from 'lucide-react';
import { useState } from 'react';
import { usePuede } from '@/components/shared/sesion-panel-contexto';
import { useOperacionPanel } from '@/components/shared/use-operacion-panel';
import { useAvisos } from '@/components/shared/ui/avisos';
import { Boton } from '@/components/shared/ui/boton';

/**
 * Exporta a Excel el reporte comercial con el mismo periodo y responsable que se están viendo. Cada exportación deja
 * constancia de acceso en la auditoría (quién, cuándo, periodo y filtro).
 */
export function AccionesReporteComercial({ filtros }: { filtros: string }) {
  const puede = usePuede('reportes-comerciales.exportar');
  const { descargar } = useOperacionPanel();
  const { mostrar } = useAvisos();
  const [generando, setGenerando] = useState(false);

  if (!puede) return null;

  async function exportar() {
    setGenerando(true);
    const resultado = await descargar(`servicios/reportes-comerciales/exportar${filtros}`);
    setGenerando(false);
    if (resultado.ok) mostrar('exito', 'Reporte generado con el periodo aplicado. La exportación quedó registrada.');
    else mostrar('error', resultado.error.message);
  }

  return (
    <Boton tono="servicios" variante="secundario" icono={<FileSpreadsheet className="h-4 w-4" aria-hidden="true" />} cargando={generando} onClick={() => void exportar()}>
      Exportar Excel
    </Boton>
  );
}
