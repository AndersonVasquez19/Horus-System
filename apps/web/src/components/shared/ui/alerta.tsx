import { AlertTriangle, CheckCircle2, Info, XCircle } from 'lucide-react';
import type { ReactNode } from 'react';

type TipoAlerta = 'exito' | 'aviso' | 'error' | 'info';

const ESTILOS: Record<TipoAlerta, { caja: string; Icono: typeof Info }> = {
  exito: { caja: 'border-green-200 bg-green-50 text-estado-exito', Icono: CheckCircle2 },
  aviso: { caja: 'border-amber-200 bg-amber-50 text-estado-aviso', Icono: AlertTriangle },
  error: { caja: 'border-red-200 bg-red-50 text-estado-error', Icono: XCircle },
  info: { caja: 'border-blue-200 bg-blue-50 text-estado-info', Icono: Info },
};

interface AlertaProps {
  tipo?: TipoAlerta;
  titulo?: string;
  children: ReactNode;
  className?: string;
}

/** Mensaje en línea. Los errores se anuncian de inmediato (`role="alert"`); el resto, con cortesía. */
export function Alerta({ tipo = 'info', titulo, children, className = '' }: AlertaProps) {
  const { caja, Icono } = ESTILOS[tipo];
  return (
    <div role={tipo === 'error' ? 'alert' : 'status'} className={`flex gap-3 rounded-lg border p-3 text-sm ${caja} ${className}`}>
      <Icono className="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />
      <div className="space-y-1">
        {titulo ? <p className="font-semibold">{titulo}</p> : null}
        <div className="text-panel-text">{children}</div>
      </div>
    </div>
  );
}
