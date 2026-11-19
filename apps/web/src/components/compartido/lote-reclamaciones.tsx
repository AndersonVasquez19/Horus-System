'use client';

import { Users } from 'lucide-react';
import { useRouter } from 'next/navigation';
import { useState } from 'react';
import { usePuede } from '@/components/shared/sesion-panel-contexto';
import { useOperacionPanel } from '@/components/shared/use-operacion-panel';
import { Alerta } from '@/components/shared/ui/alerta';
import { useAvisos } from '@/components/shared/ui/avisos';
import { Boton, type TonoUi } from '@/components/shared/ui/boton';
import { CampoSeleccion } from '@/components/shared/ui/campo';

export interface CasoLote {
  id: string;
  numero: string;
  consumidor: string;
}

/** Asignación rápida de responsable en lote para reclamaciones abiertas visibles (`reclamaciones.gestionar`). */
export function AsignacionLoteReclamaciones({
  casos,
  responsables,
  tono,
}: {
  casos: CasoLote[];
  responsables: { id: number; name: string }[];
  tono: TonoUi;
}) {
  const puedeGestionar = usePuede('reclamaciones.gestionar');
  const router = useRouter();
  const { ejecutar } = useOperacionPanel();
  const { mostrar } = useAvisos();
  const [abierto, setAbierto] = useState(false);
  const [seleccionados, setSeleccionados] = useState<string[]>([]);
  const [responsableId, setResponsableId] = useState('');
  const [ocupado, setOcupado] = useState(false);
  const [error, setError] = useState<string | null>(null);

  if (!puedeGestionar || casos.length === 0) return null;

  const todosMarcados = casos.every((c) => seleccionados.includes(c.id));

  function alternar(id: string) {
    setSeleccionados((prev) => (prev.includes(id) ? prev.filter((x) => x !== id) : [...prev, id]));
  }

  function alternarTodos() {
    setSeleccionados(todosMarcados ? [] : casos.map((c) => c.id));
  }

  async function asignar() {
    if (seleccionados.length === 0) return;
    setOcupado(true);
    setError(null);
    const r = await ejecutar<{ message: string }>('compartido/reclamaciones/asignar-lote', 'POST', {
      identificadores: seleccionados,
      responsable_id: responsableId === '' ? null : Number(responsableId),
    });
    setOcupado(false);
    if (!r.ok) {
      setError(r.error.message);
      return;
    }
    setSeleccionados([]);
    setAbierto(false);
    mostrar('exito', r.datos.message);
    router.refresh();
  }

  return (
    <div className="rounded-xl border border-panel-border bg-panel-surface p-3 shadow-sm">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <div className="flex items-center gap-2 text-sm text-panel-text">
          <Users className="h-4 w-4 text-panel-muted" aria-hidden="true" />
          <span className="font-medium">Asignación masiva</span>
          <span className="text-xs text-panel-muted">({casos.length} caso(s) abierto(s) visible(s))</span>
        </div>
        <Boton tono={tono} variante="secundario" type="button" onClick={() => setAbierto((v) => !v)}>
          {abierto ? 'Ocultar' : 'Asignar varios'}
        </Boton>
      </div>

      {abierto ? (
        <div className="mt-3 space-y-3 border-t border-panel-border pt-3">
          {error ? <Alerta tipo="error">{error}</Alerta> : null}
          <div className="flex flex-wrap items-center justify-between gap-2">
            <button
              type="button"
              onClick={alternarTodos}
              className="text-xs font-semibold text-panel-text hover:underline"
            >
              {todosMarcados ? 'Deseleccionar todos' : `Seleccionar los ${casos.length} casos abiertos`}
            </button>
            <span className="text-xs text-panel-muted">{seleccionados.length} seleccionado(s)</span>
          </div>
          <ul className="grid max-h-44 gap-1.5 overflow-y-auto sm:grid-cols-2 lg:grid-cols-3">
            {casos.map((c) => (
              <li key={c.id}>
                <label className="flex cursor-pointer items-center gap-2 rounded-lg border border-panel-border bg-panel-bg px-2.5 py-1.5 text-xs hover:bg-panel-surface">
                  <input
                    type="checkbox"
                    checked={seleccionados.includes(c.id)}
                    onChange={() => alternar(c.id)}
                    className="h-3.5 w-3.5 rounded border-panel-border"
                  />
                  <span className="font-mono font-semibold text-panel-text">{c.numero}</span>
                  <span className="truncate text-panel-muted">{c.consumidor}</span>
                </label>
              </li>
            ))}
          </ul>
          <div className="flex flex-wrap items-end gap-3">
            <div className="min-w-56 flex-1">
              <CampoSeleccion
                tono={tono}
                etiqueta="Nuevo responsable"
                value={responsableId}
                onChange={(e) => setResponsableId(e.target.value)}
                opciones={{ '': 'Sin asignar', ...Object.fromEntries(responsables.map((u) => [String(u.id), u.name])) }}
              />
            </div>
            <Boton tono={tono} type="button" disabled={seleccionados.length === 0} cargando={ocupado} onClick={() => void asignar()}>
              Aplicar a {seleccionados.length}
            </Boton>
          </div>
        </div>
      ) : null}
    </div>
  );
}
