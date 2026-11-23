'use client';

import { Download } from 'lucide-react';
import { useState } from 'react';
import { useRouter } from 'next/navigation';
import { usePuede } from '@/components/shared/sesion-panel-contexto';
import { useOperacionPanel } from '@/components/shared/use-operacion-panel';
import { useAvisos } from '@/components/shared/ui/avisos';
import { Boton } from '@/components/shared/ui/boton';
import { Campo } from '@/components/shared/ui/campo';
import { hoyLima, numero, sumarDias } from '@/lib/panel/formato';

export type GrupoRespaldo = 'academico' | 'finanzas' | 'atencion' | 'administracion';

export interface SeccionRespaldo {
  clave: string;
  etiqueta: string;
  grupo?: GrupoRespaldo;
  registros?: number;
}

const ETIQUETAS_GRUPO: Record<GrupoRespaldo, string> = {
  academico: 'Académico',
  finanzas: 'Finanzas',
  atencion: 'Atención y control',
  administracion: 'Administración',
};

const ORDEN_GRUPOS: readonly GrupoRespaldo[] = ['academico', 'finanzas', 'atencion', 'administracion'];

/**
 * Descarga del ZIP de exportación administrativa con paquetes rápidos, agrupación por área,
 * conteo de registros por tabla y atajos de rango de fechas (`respaldo.exportar`).
 */
export function ExportarRespaldo({ secciones }: { secciones: SeccionRespaldo[] }) {
  const router = useRouter();
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

  function seleccionarGrupo(grupo: GrupoRespaldo) {
    setSeleccionadas(secciones.filter((s) => (s.grupo ?? 'administracion') === grupo).map((s) => s.clave));
  }

  function aplicarRango(tipo: 'mes' | '30d' | 'anio' | 'todo') {
    if (tipo === 'todo') {
      setDesde('');
      setHasta('');
      return;
    }
    const hoy = hoyLima();
    setHasta(hoy);
    if (tipo === '30d') {
      setDesde(sumarDias(hoy, -29));
    } else if (tipo === 'mes') {
      setDesde(`${hoy.slice(0, 7)}-01`);
    } else {
      setDesde(`${hoy.slice(0, 4)}-01-01`);
    }
  }

  async function exportar() {
    if (seleccionadas.length === 0) {
      mostrar('error', 'Selecciona al menos una sección para descargar.');
      return;
    }
    if (desde !== '' && hasta !== '' && desde > hasta) {
      mostrar('error', 'La fecha «Desde» no puede ser posterior a «Hasta».');
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
    if (resultado.ok) {
      mostrar('exito', 'Descarga lista. No se guarda ninguna copia en el servidor.');
      router.refresh();
    } else {
      mostrar('error', resultado.error.message);
    }
  }

  const porGrupo = ORDEN_GRUPOS.map((grupo) => ({
    grupo,
    etiqueta: ETIQUETAS_GRUPO[grupo],
    items: secciones.filter((s) => (s.grupo ?? 'administracion') === grupo),
  })).filter((g) => g.items.length > 0);

  return (
    <div className="space-y-4">
      <div className="rounded-xl border border-panel-border bg-panel-surface p-5 shadow-sm space-y-5">
        <div className="flex flex-wrap items-start justify-between gap-3">
          <div>
            <h2 className="font-heading text-base font-semibold text-panel-text">Secciones incluidas</h2>
            <p className="text-xs text-panel-muted">Usa un paquete rápido o marca manualmente las tablas que necesitas.</p>
          </div>
          {puede ? (
            <div className="flex flex-wrap gap-1.5 text-xs">
              <button
                type="button"
                onClick={() => setSeleccionadas(secciones.map((s) => s.clave))}
                className="rounded-lg border border-panel-border bg-panel-bg px-2.5 py-1 font-semibold text-academico-700 hover:bg-academico-50"
              >
                Todo
              </button>
              <button
                type="button"
                onClick={() => seleccionarGrupo('academico')}
                className="rounded-lg border border-panel-border bg-panel-bg px-2.5 py-1 font-medium text-panel-text hover:bg-panel-surface"
              >
                Solo Académico
              </button>
              <button
                type="button"
                onClick={() => seleccionarGrupo('finanzas')}
                className="rounded-lg border border-panel-border bg-panel-bg px-2.5 py-1 font-medium text-panel-text hover:bg-panel-surface"
              >
                Solo Finanzas
              </button>
              <button
                type="button"
                onClick={() => seleccionarGrupo('atencion')}
                className="rounded-lg border border-panel-border bg-panel-bg px-2.5 py-1 font-medium text-panel-text hover:bg-panel-surface"
              >
                Atención y control
              </button>
              <button
                type="button"
                onClick={() => setSeleccionadas([])}
                className="rounded-lg border border-panel-border bg-panel-surface px-2.5 py-1 font-medium text-panel-muted hover:text-panel-text"
              >
                Ninguna
              </button>
            </div>
          ) : null}
        </div>

        <div className="space-y-4">
          {porGrupo.map((bloque) => (
            <div key={bloque.grupo} className="space-y-2">
              <h3 className="text-xs font-semibold uppercase tracking-wide text-panel-muted">{bloque.etiqueta}</h3>
              <ul className="grid gap-2 sm:grid-cols-2">
                {bloque.items.map((seccion) => {
                  const marcada = seleccionadas.includes(seccion.clave);
                  return (
                    <li key={seccion.clave}>
                      <label className="flex cursor-pointer items-center justify-between gap-2 rounded-lg border border-panel-border bg-panel-bg px-3 py-2 text-sm text-panel-text hover:border-academico-300">
                        <span className="flex items-center gap-2 min-w-0">
                          <input
                            type="checkbox"
                            checked={marcada}
                            disabled={!puede}
                            onChange={() => alternar(seccion.clave)}
                            className="h-4 w-4 rounded border-panel-border accent-academico-700"
                          />
                          <span className="truncate">{seccion.etiqueta}</span>
                        </span>
                        {typeof seccion.registros === 'number' ? (
                          <span className="shrink-0 rounded-full bg-panel-surface px-2 py-0.5 text-[11px] font-medium tabular-nums text-panel-muted border border-panel-border">
                            {numero(seccion.registros)}
                          </span>
                        ) : null}
                      </label>
                    </li>
                  );
                })}
              </ul>
            </div>
          ))}
        </div>

        {puede ? (
          <div className="space-y-3 border-t border-panel-border pt-4">
            <div className="flex flex-wrap items-center gap-2 text-xs">
              <span className="font-medium text-panel-muted">Rango rápido:</span>
              <button type="button" onClick={() => aplicarRango('mes')} className="rounded-md border border-panel-border bg-panel-bg px-2.5 py-1 font-medium text-panel-text hover:bg-panel-surface">
                Este mes
              </button>
              <button type="button" onClick={() => aplicarRango('30d')} className="rounded-md border border-panel-border bg-panel-bg px-2.5 py-1 font-medium text-panel-text hover:bg-panel-surface">
                Últimos 30 días
              </button>
              <button type="button" onClick={() => aplicarRango('anio')} className="rounded-md border border-panel-border bg-panel-bg px-2.5 py-1 font-medium text-panel-text hover:bg-panel-surface">
                Este año
              </button>
              <button type="button" onClick={() => aplicarRango('todo')} className="rounded-md border border-panel-border bg-panel-surface px-2.5 py-1 font-medium text-panel-muted hover:text-panel-text">
                Todo el histórico
              </button>
            </div>
            <div className="grid gap-4 sm:grid-cols-2">
              <Campo etiqueta="Desde (opcional)" type="date" value={desde} onChange={(e) => setDesde(e.target.value)} />
              <Campo etiqueta="Hasta (opcional)" type="date" value={hasta} onChange={(e) => setHasta(e.target.value)} />
            </div>
          </div>
        ) : null}

        <p className="text-xs text-panel-muted">
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
