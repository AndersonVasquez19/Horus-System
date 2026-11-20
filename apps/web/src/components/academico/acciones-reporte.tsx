'use client';

import { Download, Eye, FileSpreadsheet, FileText, Printer } from 'lucide-react';
import { useState } from 'react';
import { usePuede } from '@/components/shared/sesion-panel-contexto';
import { useOperacionPanel } from '@/components/shared/use-operacion-panel';
import { useAvisos } from '@/components/shared/ui/avisos';
import { Boton } from '@/components/shared/ui/boton';
import { Dialogo } from '@/components/shared/ui/dialogo';
import { MenuFila, type ElementoMenuFila } from '@/components/shared/ui/menu-fila';
import { ZonaImpresion, imprimirSoloZona } from '@/components/shared/ui/zona-impresion';

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

function ResumenVista({ vista }: { vista: VistaPreviaReporte }) {
  return (
    <div className="grid gap-3 sm:grid-cols-4">
      {vista.resumen.map((item) => (
        <div key={item.etiqueta} className="rounded-lg border border-panel-border bg-panel-bg px-3 py-2">
          <p className="text-xs text-panel-muted">{item.etiqueta}</p>
          <p className="text-sm font-semibold text-panel-text">{item.valor}</p>
        </div>
      ))}
    </div>
  );
}

function TablaVista({ vista, escritorio }: { vista: VistaPreviaReporte; escritorio: boolean }) {
  return (
    <table className={`w-full text-left ${escritorio ? 'text-xs' : 'text-sm'}`}>
      <thead className={`border-b border-panel-border bg-panel-bg font-semibold text-panel-muted ${escritorio ? 'sticky top-0' : ''}`}>
        <tr>
          {vista.cabeceras.map((c) => (
            <th key={c} scope="col" className="px-3 py-2">{c}</th>
          ))}
        </tr>
      </thead>
      <tbody className="divide-y divide-panel-border bg-panel-surface">
        {vista.filas.map((fila, idx) => (
          <tr key={idx}>
            {fila.map((celda, cIdx) => (
              <td key={cIdx} className="px-3 py-2 text-panel-text">{celda}</td>
            ))}
          </tr>
        ))}
      </tbody>
    </table>
  );
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
  /** Fecha y hora en que se abrió la vista previa: va en el encabezado de la hoja impresa. */
  const [generadoEn, setGenerado] = useState('');

  function abrirVista() {
    setGenerado(new Intl.DateTimeFormat('es-PE', { dateStyle: 'long', timeStyle: 'short', timeZone: 'America/Lima' }).format(new Date()));
    setAbierto(true);
  }

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

  // Una sola acción «Exportar» en lugar de hasta tres botones iguales: las exportaciones se eligen en el menú.
  const opcionesExportar: ElementoMenuFila[] = [
    ...(puedeExcel ? [{ tipo: 'accion' as const, etiqueta: 'Excel', icono: FileSpreadsheet, onClick: () => void exportar('excel') }] : []),
    ...(puedePdf && tipo === 'matriculas' && variosLotes
      ? [{ tipo: 'accion' as const, etiqueta: 'PDF del lote actual', icono: FileText, onClick: () => void exportar('pdf') }]
      : []),
    ...(puedePdf
      ? [{ tipo: 'accion' as const, etiqueta: tipo === 'matriculas' ? 'PDF completo' : 'PDF', icono: FileText, onClick: () => void exportar('pdf_completo') }]
      : []),
  ];

  return (
    <>
      {vistaPrevia ? (
        <Boton variante="secundario" icono={<Eye className="h-4 w-4" aria-hidden="true" />} onClick={abrirVista}>
          Vista previa
        </Boton>
      ) : null}
      <MenuFila etiqueta="Exportar reporte" disparador={{ texto: 'Exportar', icono: Download }} cargando={generando !== null} elementos={opcionesExportar} />

      {vistaPrevia ? (
        <Dialogo
          abierto={abierto}
          titulo={`Vista previa · ${vistaPrevia.titulo}`}
          descripcion={vistaPrevia.subtitulo}
          ancho="lg"
          onCerrar={() => setAbierto(false)}
        >
          <div className="space-y-4">
            <ResumenVista vista={vistaPrevia} />
            <div className="max-h-96 overflow-auto rounded-lg border border-panel-border">
              <TablaVista vista={vistaPrevia} escritorio />
            </div>
            <div className="pie-acciones">
              <Boton variante="secundario" onClick={() => setAbierto(false)}>Cerrar</Boton>
              <Boton variante="secundario" icono={<Printer className="h-4 w-4" aria-hidden="true" />} onClick={imprimirSoloZona}>
                Imprimir
              </Boton>
            </div>
          </div>
        </Dialogo>
      ) : null}

      {vistaPrevia && abierto ? (
        <ZonaImpresion>
          <h1 className="text-xl font-bold text-black">{vistaPrevia.titulo}</h1>
          <p className="mt-1 text-sm text-slate-700">{vistaPrevia.subtitulo}</p>
          <p className="mb-4 text-xs text-slate-600">Generado el {generadoEn}</p>
          <div className="space-y-4">
            <ResumenVista vista={vistaPrevia} />
            <TablaVista vista={vistaPrevia} escritorio={false} />
          </div>
        </ZonaImpresion>
      ) : null}
    </>
  );
}
