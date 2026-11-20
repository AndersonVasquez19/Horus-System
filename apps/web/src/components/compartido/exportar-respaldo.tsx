'use client';

import { Download } from 'lucide-react';
import { useState } from 'react';
import { usePuede } from '@/components/shared/sesion-panel-contexto';
import { useOperacionPanel } from '@/components/shared/use-operacion-panel';
import { useAvisos } from '@/components/shared/ui/avisos';
import { Boton } from '@/components/shared/ui/boton';
import { Campo } from '@/components/shared/ui/campo';

export interface SeccionRespaldo {
  clave: string;
  etiqueta: string;
}

/**
 * Descarga del ZIP de exportación administrativa con selección opcional de tablas y rango de fechas (`respaldo.exportar`).
 */
export function ExportarRespaldo({ secciones }: { secciones: SeccionRespaldo[] }) {
  const puede = usePuede('respaldo.exportar');
  const { descargar } = useOperacionPanel();
  const { mostrar } = useAvisos();
  const [seleccionadas, setSeleccionadas] = useState<string[]>(() => secciones.map((s) => s.clave));
  const [desde, setDesde] = useState('');
  const [hasta, setHasta] = useState('');
  const [exportando, setExportando] = useState(false);

  function alternar(clave: string) {
    setSeleccionadas((actuales) =>
      actuales.includes(clave) ? actuales.filter((c) => c !== clave) : [...actuales, clave],
    );
  }

  async function exportar() {
    if (seleccionadas.length === 0) {
      mostrar('error', 'Selecciona al menos una sección para descargar.');
      return;
    }
    setExportando(true);
    const qs = new URLSearchParams();
    if (seleccionadas.length < secciones.length) {
      qs.set('tablas', seleccionadas.join(','));
    }
    if (desde !== '') qs.set('desde', desde);
    if (hasta !== '') qs.set('hasta', hasta);
    const sufijo = qs.toString() === '' ? '' : `?${qs.toString()}`;
    const resultado = await descargar(`compartido/respaldo/exportar${sufijo}`);
    setExportando(false);
    if (resultado.ok) mostrar('exito', 'Descarga lista. No se guarda ninguna copia en el servidor.');
    else mostrar('error', resultado.error.message);
  }

  return (
    <div className="space-y-4">
      <div className="rounded-xl border border-panel-border bg-panel-surface p-5 shadow-sm">
        <div className="flex flex-wrap items-center justify-between gap-2">
          <div>
            <h2 className="font-heading text-base font-semibold text-panel-text">Secciones incluidas</h2>
            <p className="text-xs text-panel-muted">Elige qué tablas descargar y acota el rango de fechas si lo necesitas.</p>
          </div>
          {puede ? (
            <div className="flex gap-2 text-xs">
              <button
                type="button"
                onClick={() => setSeleccionadas(secciones.map((s) => s.clave))}
                className="font-semibold text-academico-700 hover:underline"
              >
                Seleccionar todas
              </button>
              <span className="text-panel-border">·</span>
              <button
                type="button"
                onClick={() => setSeleccionadas([])}
                className="font-semibold text-panel-muted hover:underline"
              >
                Ninguna
              </button>
            </div>
          ) : null}
        </div>

        <ul className="mt-4 grid gap-2 sm:grid-cols-2">
          {secciones.map((seccion) => {
            const marcada = seleccionadas.includes(seccion.clave);
            return (
              <li key={seccion.clave}>
                <label className="flex cursor-pointer items-center gap-2 rounded-lg border border-panel-border bg-panel-bg px-3 py-2 text-sm text-panel-text hover:border-academico-300">
                  <input
                    type="checkbox"
                    checked={marcada}
                    disabled={!puede}
                    onChange={() => alternar(seccion.clave)}
                    className="h-4 w-4 rounded border-panel-border accent-academico-700"
                  />
                  <span>{seccion.etiqueta}</span>
                </label>
              </li>
            );
          })}
        </ul>

        {puede ? (
          <div className="mt-4 grid gap-4 border-t border-panel-border pt-4 sm:grid-cols-2">
            <Campo etiqueta="Desde (opcional)" type="date" value={desde} onChange={(e) => setDesde(e.target.value)} />
            <Campo etiqueta="Hasta (opcional)" type="date" value={hasta} onChange={(e) => setHasta(e.target.value)} />
          </div>
        ) : null}

        <p className="mt-4 text-xs text-panel-muted">
          No se incluyen contraseñas ni claves de acceso ni enlaces privados de grupos; los secretos de configuración se ocultan.
        </p>
      </div>

      {puede ? (
        <Boton icono={<Download className="h-4 w-4" aria-hidden="true" />} cargando={exportando} onClick={() => void exportar()}>
          {exportando ? 'Preparando ZIP…' : `Descargar datos (${seleccionadas.length})`}
        </Boton>
      ) : (
        <p className="text-sm text-panel-muted">Tu cuenta puede ver qué incluye la descarga, pero no descargarla.</p>
      )}
    </div>
  );
}
