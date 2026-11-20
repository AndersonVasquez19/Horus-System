'use client';

import { Download, FileSpreadsheet } from 'lucide-react';
import { useState } from 'react';
import { usePuede } from '@/components/shared/sesion-panel-contexto';
import { useOperacionPanel } from '@/components/shared/use-operacion-panel';
import { useAvisos } from '@/components/shared/ui/avisos';
import { Boton, type TonoUi } from '@/components/shared/ui/boton';

type Formato = 'json' | 'csv' | 'xlsx';

function urlConFormato(filtros: string, formato: Formato): string {
  if (formato === 'json') return `compartido/auditoria/exportar${filtros}`;
  const qs = new URLSearchParams(filtros.startsWith('?') ? filtros.slice(1) : filtros);
  qs.set('formato', formato);
  return `compartido/auditoria/exportar?${qs.toString()}`;
}

/** Descarga del historial con los filtros vigentes en JSON, CSV o Excel (`auditoria.exportar`). */
export function ExportarAuditoria({ tono, filtros }: { tono: TonoUi; filtros: string }) {
  const puede = usePuede('auditoria.exportar');
  const { descargar } = useOperacionPanel();
  const { mostrar } = useAvisos();
  const [exportando, setExportando] = useState<Formato | null>(null);

  if (!puede) return null;

  async function exportar(formato: Formato) {
    setExportando(formato);
    const resultado = await descargar(urlConFormato(filtros, formato));
    setExportando(null);
    if (!resultado.ok) {
      mostrar('error', resultado.error.message);
      return;
    }
    if (resultado.aviso) mostrar('exito', resultado.aviso);
    else mostrar('exito', `Historial exportado en ${formato.toUpperCase()} con los filtros aplicados.`);
  }

  return (
    <div className="flex flex-wrap items-center gap-2">
      <Boton tono={tono} variante="secundario" icono={<Download className="h-4 w-4" aria-hidden="true" />} cargando={exportando === 'csv'} disabled={exportando !== null} onClick={() => void exportar('csv')}>
        Exportar CSV
      </Boton>
      <Boton tono={tono} variante="secundario" icono={<FileSpreadsheet className="h-4 w-4" aria-hidden="true" />} cargando={exportando === 'xlsx'} disabled={exportando !== null} onClick={() => void exportar('xlsx')}>
        Exportar Excel
      </Boton>
      <Boton tono={tono} variante="secundario" icono={<Download className="h-4 w-4" aria-hidden="true" />} cargando={exportando === 'json'} disabled={exportando !== null} onClick={() => void exportar('json')}>
        Exportar JSON
      </Boton>
    </div>
  );
}
