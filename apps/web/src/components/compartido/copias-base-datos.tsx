'use client';

import { Database, Download } from 'lucide-react';
import { useState } from 'react';
import { usePuede } from '@/components/shared/sesion-panel-contexto';
import { useOperacionPanel } from '@/components/shared/use-operacion-panel';
import { useAvisos } from '@/components/shared/ui/avisos';
import { Boton } from '@/components/shared/ui/boton';
import { numero } from '@/lib/panel/formato';

export interface CopiaBaseDatos {
  nombre: string;
  bytes: number;
  fecha_local: string;
  sha256: string | null;
}

function tamano(bytes: number): string {
  if (bytes < 1024 * 1024) return `${numero(Math.max(1, Math.round(bytes / 1024)))} KB`;
  return `${(bytes / (1024 * 1024)).toLocaleString('es-PE', { maximumFractionDigits: 1 })} MB`;
}

/**
 * Copias locales de la base de datos (`horus:respaldo`). Solo las ve y descarga quien tiene `respaldo.exportar`
 * (por defecto, el administrador). Laravel verifica la suma SHA-256 antes de entregar cada archivo y audita la descarga.
 */
export function CopiasBaseDatos({
  copias,
  programado,
  retencionDias,
  minimo,
}: {
  copias: CopiaBaseDatos[];
  programado: boolean;
  retencionDias: number;
  minimo: number;
}) {
  const puede = usePuede('respaldo.exportar');
  const { descargar } = useOperacionPanel();
  const { mostrar } = useAvisos();
  const [descargando, setDescargando] = useState<string | null>(null);

  async function bajar(nombre: string) {
    setDescargando(nombre);
    const resultado = await descargar(`compartido/respaldo/copias/${nombre}`, 'GET');
    setDescargando(null);
    if (resultado.ok) {
      mostrar('exito', 'Descarga lista. La descarga quedó registrada en el historial de actividad.');
    } else {
      mostrar('error', resultado.error.message);
    }
  }

  return (
    <div className="space-y-3">
      <p className="text-xs text-panel-muted">
        {programado ? 'El respaldo diario está programado.' : 'El respaldo programado está apagado: las copias se crean con el comando del servidor.'}{' '}
        Se conservan {retencionDias} días y siempre las {minimo} más recientes.
      </p>
      {copias.some((copia) => copia.bytes > 4 * 1024 * 1024) ? (
        <p className="rounded-lg border border-panel-border bg-panel-bg px-3 py-2 text-xs text-panel-muted">
          Las copias grandes pueden no descargarse por el panel (el puente web las carga en memoria y limita el tiempo a 30 s). Si falla,
          toma la copia directamente del servidor.
        </p>
      ) : null}
      {copias.length === 0 ? (
        <p className="text-sm text-panel-muted">Todavía no hay copias de la base de datos.</p>
      ) : (
        <ul className="divide-y divide-panel-border text-sm">
          {copias.map((copia) => (
            <li key={copia.nombre} className="flex items-center justify-between gap-3 py-2.5 first:pt-0 last:pb-0">
              <div className="flex min-w-0 items-start gap-2.5">
                <Database className="mt-0.5 h-4 w-4 shrink-0 text-panel-muted" aria-hidden="true" />
                <div className="min-w-0">
                  <p className="truncate font-medium text-panel-text">{copia.fecha_local}</p>
                  <p className="truncate text-xs text-panel-muted">
                    {tamano(copia.bytes)}
                    {copia.sha256 ? ` · sha256 ${copia.sha256.slice(0, 12)}…` : ' · sin suma de verificación'}
                  </p>
                </div>
              </div>
              {puede ? (
                <Boton
                  type="button"
                  variante="secundario"
                  className="px-3 py-1.5"
                  icono={<Download className="h-4 w-4" aria-hidden="true" />}
                  cargando={descargando === copia.nombre}
                  disabled={descargando !== null}
                  onClick={() => void bajar(copia.nombre)}
                  aria-label={`Descargar la copia del ${copia.fecha_local}`}
                >
                  Descargar
                </Boton>
              ) : null}
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}
