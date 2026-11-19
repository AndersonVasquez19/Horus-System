'use client';

import { Eye, FileSpreadsheet, FileText, Printer } from 'lucide-react';
import { useState } from 'react';
import { usePuede } from '@/components/shared/sesion-panel-contexto';
import { useOperacionPanel } from '@/components/shared/use-operacion-panel';
import { useAvisos } from '@/components/shared/ui/avisos';
import { Boton } from '@/components/shared/ui/boton';
import { Dialogo } from '@/components/shared/ui/dialogo';

type Formato = 'excel' | 'pdf' | 'pdf_completo';

export interface VistaPreviaReporte {
  titulo: string;
  subtitulo: string;
  resumen: { etiqueta: string; valor: string }[];
  cabeceras: string[];
  filas: string[][];
}

interface AccionesReporteProps {
  filtros: string;
  filtrosLote: string;
  tipo: string;
  variosLotes: boolean;
  vistaPrevia?: VistaPreviaReporte;
}

function anexarParametro(query: string, clave: string, valor: string): string {
  const qs = new URLSearchParams(query.startsWith('?') ? query.slice(1) : query);
  qs.set(clave, valor);
  const texto = qs.toString();
  return texto ? `?${texto}` : '';
}

/**
 * Exportaciones del reporte académico y vista previa imprimible dentro de la misma página.
 */
export function AccionesReporte({ filtros, filtrosLote, tipo, variosLotes, vistaPrevia }: AccionesReporteProps) {
  const puedeExcel = usePuede('reportes.exportar.excel');
  const puedePdf = usePuede('reportes.exportar.pdf');
  const { descargar } = useOperacionPanel();
  const { mostrar } = useAvisos();
  const [generando, setGenerando] = useState<Formato | null>(null);
  const [abierto, setAbierto] = useState(false);

  async function exportar(formato: Formato) {
    setGenerando(formato);
    const destino = formato === 'excel'
      ? `academico/reportes/exportar/excel${filtros}`
      : formato === 'pdf'
        ? `academico/reportes/exportar/pdf${filtrosLote}`
        : `academico/reportes/exportar/pdf${anexarParametro(filtros, 'completo', '1')}`;

    const resultado = await descargar(destino);
    setGenerando(null);
    if (resultado.ok) mostrar('exito', 'Reporte generado con los filtros aplicados.');
    else mostrar('error', resultado.error.message);
  }

  return (
    <>
      {vistaPrevia ? (
        <Boton variante="secundario" icono={<Eye className="h-4 w-4" aria-hidden="true" />} onClick={() => setAbierto(true)}>
          Vista previa
        </Boton>
      ) : null}
      {puedeExcel ? (
        <Boton variante="secundario" icono={<FileSpreadsheet className="h-4 w-4" aria-hidden="true" />} cargando={generando === 'excel'} disabled={generando !== null} onClick={() => void exportar('excel')}>
          Exportar Excel
        </Boton>
      ) : null}
      {puedePdf && tipo === 'matriculas' && variosLotes ? (
        <Boton variante="secundario" icono={<FileText className="h-4 w-4" aria-hidden="true" />} cargando={generando === 'pdf'} disabled={generando !== null} onClick={() => void exportar('pdf')}>
          PDF (lote actual)
        </Boton>
      ) : null}
      {puedePdf ? (
        <Boton variante="secundario" icono={<FileText className="h-4 w-4" aria-hidden="true" />} cargando={generando === 'pdf_completo'} disabled={generando !== null} onClick={() => void exportar('pdf_completo')}>
          {tipo === 'matriculas' ? 'Exportar PDF completo' : 'Exportar PDF'}
        </Boton>
      ) : null}

      {vistaPrevia ? (
        <Dialogo
          abierto={abierto}
          titulo={`Vista previa · ${vistaPrevia.titulo}`}
          descripcion={vistaPrevia.subtitulo}
          ancho="lg"
          onCerrar={() => setAbierto(false)}
        >
          <div className="space-y-4">
            <div className="grid gap-3 sm:grid-cols-4">
              {vistaPrevia.resumen.map((item) => (
                <div key={item.etiqueta} className="rounded-lg border border-panel-border bg-panel-bg px-3 py-2">
                  <p className="text-xs text-panel-muted">{item.etiqueta}</p>
                  <p className="text-sm font-semibold text-panel-text">{item.valor}</p>
                </div>
              ))}
            </div>
            <div className="max-h-96 overflow-auto rounded-lg border border-panel-border">
              <table className="w-full text-left text-xs">
                <thead className="sticky top-0 border-b border-panel-border bg-panel-bg font-semibold text-panel-muted">
                  <tr>
                    {vistaPrevia.cabeceras.map((c) => (
                      <th key={c} scope="col" className="px-3 py-2">{c}</th>
                    ))}
                  </tr>
                </thead>
                <tbody className="divide-y divide-panel-border bg-panel-surface">
                  {vistaPrevia.filas.map((fila, idx) => (
                    <tr key={idx}>
                      {fila.map((celda, cIdx) => (
                        <td key={cIdx} className="px-3 py-2 text-panel-text">{celda}</td>
                      ))}
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
            <div className="flex flex-col-reverse gap-2 border-t border-panel-border pt-3 sm:flex-row sm:justify-end">
              <Boton variante="secundario" onClick={() => setAbierto(false)}>Cerrar</Boton>
              <Boton variante="secundario" icono={<Printer className="h-4 w-4" aria-hidden="true" />} onClick={() => window.print()}>
                Imprimir
              </Boton>
            </div>
          </div>
        </Dialogo>
      ) : null}
    </>
  );
}
