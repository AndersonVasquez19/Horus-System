'use client';

import { FileSpreadsheet, FileText } from 'lucide-react';
import { useState } from 'react';
import { usePuede } from '@/components/shared/sesion-panel-contexto';
import { useOperacionPanel } from '@/components/shared/use-operacion-panel';
import { useAvisos } from '@/components/shared/ui/avisos';
import { Boton } from '@/components/shared/ui/boton';

type Formato = 'excel' | 'pdf';

/**
 * Exportaciones del reporte de matrículas, cada una con su permiso. El Excel incluye todos los resultados del
 * filtro; el PDF, solo el lote que se está viendo (el servidor acota cada PDF a un lote).
 */
export function AccionesReporte({ filtros, filtrosLote }: { filtros: string; filtrosLote: string }) {
  const puedeExcel = usePuede('reportes.exportar.excel');
  const puedePdf = usePuede('reportes.exportar.pdf');
  const { descargar } = useOperacionPanel();
  const { mostrar } = useAvisos();
  const [generando, setGenerando] = useState<Formato | null>(null);

  async function exportar(formato: Formato) {
    setGenerando(formato);
    const resultado = await descargar(`academico/reportes/exportar/${formato}${formato === 'pdf' ? filtrosLote : filtros}`);
    setGenerando(null);
    if (resultado.ok) mostrar('exito', 'Reporte generado con los filtros aplicados.');
    else mostrar('error', resultado.error.message);
  }

  if (!puedeExcel && !puedePdf) return null;

  return (
    <>
      {puedeExcel ? (
        <Boton variante="secundario" icono={<FileSpreadsheet className="h-4 w-4" aria-hidden="true" />} cargando={generando === 'excel'} disabled={generando !== null} onClick={() => void exportar('excel')}>
          Exportar Excel
        </Boton>
      ) : null}
      {puedePdf ? (
        <Boton variante="secundario" icono={<FileText className="h-4 w-4" aria-hidden="true" />} cargando={generando === 'pdf'} disabled={generando !== null} onClick={() => void exportar('pdf')}>
          Exportar PDF (lote actual)
        </Boton>
      ) : null}
    </>
  );
}
