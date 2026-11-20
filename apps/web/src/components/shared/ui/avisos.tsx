'use client';

import { CheckCircle2, X, XCircle } from 'lucide-react';
import { createContext, use, useCallback, useEffect, useMemo, useRef, useState, type ReactNode } from 'react';

type TipoAviso = 'exito' | 'error';

interface Aviso {
  id: number;
  tipo: TipoAviso;
  texto: string;
}

interface ContextoAvisos {
  mostrar: (tipo: TipoAviso, texto: string) => void;
}

const Contexto = createContext<ContextoAvisos | null>(null);
const DURACION_MS = 6000;

/**
 * Avisos breves tras una operación (toasts). Una región `aria-live` siempre presente los anuncia: cortés para los
 * éxitos y asertiva para los errores. No sustituyen a los errores en línea de los formularios.
 */
export function ProveedorAvisos({ children }: { children: ReactNode }) {
  const [avisos, setAvisos] = useState<Aviso[]>([]);
  const siguiente = useRef(1);

  const cerrar = useCallback((id: number) => setAvisos((actuales) => actuales.filter((aviso) => aviso.id !== id)), []);
  const mostrar = useCallback((tipo: TipoAviso, texto: string) => {
    const id = siguiente.current++;
    setAvisos((actuales) => [...actuales.slice(-2), { id, tipo, texto }]);
  }, []);
  const valor = useMemo(() => ({ mostrar }), [mostrar]);

  return (
    <Contexto value={valor}>
      {children}
      <div className="pointer-events-none fixed inset-x-4 bottom-4 z-50 flex flex-col items-end gap-2 sm:left-auto sm:w-96">
        <div aria-live="polite" className="contents">
          {avisos.filter((aviso) => aviso.tipo === 'exito').map((aviso) => <Tostada key={aviso.id} aviso={aviso} cerrar={cerrar} />)}
        </div>
        <div aria-live="assertive" className="contents">
          {avisos.filter((aviso) => aviso.tipo === 'error').map((aviso) => <Tostada key={aviso.id} aviso={aviso} cerrar={cerrar} />)}
        </div>
      </div>
    </Contexto>
  );
}

function Tostada({ aviso, cerrar }: { aviso: Aviso; cerrar: (id: number) => void }) {
  useEffect(() => {
    const temporizador = window.setTimeout(() => cerrar(aviso.id), DURACION_MS);
    return () => window.clearTimeout(temporizador);
  }, [aviso.id, cerrar]);

  const Icono = aviso.tipo === 'exito' ? CheckCircle2 : XCircle;
  return (
    <div className="pointer-events-auto flex w-full items-start gap-3 rounded-lg border border-panel-border bg-panel-surface p-3 text-sm text-panel-text shadow-lg">
      <Icono className={`mt-0.5 h-5 w-5 shrink-0 ${aviso.tipo === 'exito' ? 'text-estado-exito' : 'text-estado-error'}`} aria-hidden="true" />
      <p className="flex-1">{aviso.texto}</p>
      <button
        type="button"
        onClick={() => cerrar(aviso.id)}
        className="rounded p-0.5 text-panel-muted hover:bg-panel-bg hover:text-panel-text focus-visible:outline-2 focus-visible:outline-panel-text"
        aria-label="Cerrar aviso"
      >
        <X className="h-4 w-4" aria-hidden="true" />
      </button>
    </div>
  );
}

export function useAvisos(): ContextoAvisos {
  const contexto = use(Contexto);
  if (contexto === null) throw new Error('useAvisos debe usarse dentro de ProveedorAvisos.');
  return contexto;
}
