'use client';

import { Download, FileSpreadsheet } from 'lucide-react';
import { useState } from 'react';
import { usePuede } from '@/components/shared/sesion-panel-contexto';
import { useOperacionPanel } from '@/components/shared/use-operacion-panel';
import { useAvisos } from './avisos';
import { Boton } from './boton';

interface BotonExportarListadoProps {
  /** Ruta relativa a `/api/bff/panel/`, p. ej. `academico/estudiantes/exportar`. */
  ruta: string;
  /** Cadena de filtros actual (empieza por `?` o vacío), sin `page`/`per_page`. */
  filtros?: string;
  /** Permiso requerido para ver los botones. */
  permiso: string;
}

function urlConFormato(ruta: string, filtros: string, formato: 'csv' | 'xlsx'): string {
  const qs = new URLSearchParams(filtros.startsWith('?') ? filtros.slice(1) : filtros);
  qs.set('formato', formato);
  return `${ruta}?${qs.toString()}`;
}

/** Botones «Exportar CSV» y «Exportar Excel» reutilizables en todos los listados del panel. */
export function BotonExportarListado({ ruta, filtros = '', permiso }: BotonExportarListadoProps) {
  const puede = usePuede(permiso);
  const { descargar } = useOperacionPanel();
  const { mostrar } = useAvisos();
  const [cargando, setCargando] = useState<'csv' | 'xlsx' | null>(null);

  if (!puede) return null;

  async function exportar(formato: 'csv' | 'xlsx') {
    setCargando(formato);
    const resultado = await descargar(urlConFormato(ruta, filtros, formato));
    setCargando(null);
    if (!resultado.ok) {
      mostrar('error', resultado.error.message);
      return;
    }
    if (resultado.aviso) {
      mostrar('exito', resultado.aviso);
    } else {
      mostrar('exito', `Exportación ${formato.toUpperCase()} generada con los filtros actuales.`);
    }
  }

  return (
    <div className="flex flex-wrap items-center gap-2">
      <Boton
        variante="secundario"
        icono={<Download className="h-4 w-4" aria-hidden="true" />}
        cargando={cargando === 'csv'}
        disabled={cargando !== null}
        onClick={() => void exportar('csv')}
      >
        Exportar CSV
      </Boton>
      <Boton
        variante="secundario"
        icono={<FileSpreadsheet className="h-4 w-4" aria-hidden="true" />}
        cargando={cargando === 'xlsx'}
        disabled={cargando !== null}
        onClick={() => void exportar('xlsx')}
      >
        Exportar Excel
      </Boton>
    </div>
  );
}
