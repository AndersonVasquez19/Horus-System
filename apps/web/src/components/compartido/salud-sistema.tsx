import { CheckCircle2, Info, TriangleAlert, XCircle } from 'lucide-react';
import { Insignia, type ColorInsignia } from '@/components/shared/ui/insignia';

export type EstadoSalud = 'ok' | 'info' | 'alerta' | 'error';

export interface ComprobacionSalud {
  clave: string;
  etiqueta: string;
  estado: EstadoSalud;
  detalle: string;
}

export interface ResumenSalud {
  estado: EstadoSalud;
  revisado_en: string;
  comprobaciones: ComprobacionSalud[];
}

const COLOR: Record<EstadoSalud, ColorInsignia> = { ok: 'verde', info: 'gris', alerta: 'ambar', error: 'rojo' };
const TEXTO: Record<EstadoSalud, string> = { ok: 'En orden', info: 'Informativo', alerta: 'Atención', error: 'Falla' };

function Icono({ estado }: { estado: EstadoSalud }) {
  const comunes = 'mt-0.5 h-4 w-4 shrink-0';
  if (estado === 'ok') return <CheckCircle2 className={`${comunes} text-green-700`} aria-hidden="true" />;
  if (estado === 'alerta') return <TriangleAlert className={`${comunes} text-amber-700`} aria-hidden="true" />;
  if (estado === 'error') return <XCircle className={`${comunes} text-red-700`} aria-hidden="true" />;
  return <Info className={`${comunes} text-panel-muted`} aria-hidden="true" />;
}

/**
 * Salud operativa del sistema (base de datos, tareas programadas, cola, disco, respaldo). Solo texto y estados: Laravel no
 * entrega datos personales en este recurso. El estado se calcula al abrir la página, no en tiempo real.
 */
export function SaludSistema({ resumen }: { resumen: ResumenSalud }) {
  return (
    <div className="space-y-3">
      <p className="flex items-center gap-2 text-sm">
        <Insignia color={COLOR[resumen.estado]}>{TEXTO[resumen.estado]}</Insignia>
        <span className="text-xs text-panel-muted">Estado general al abrir esta página.</span>
      </p>
      <ul className="divide-y divide-panel-border text-sm">
        {resumen.comprobaciones.map((c) => (
          <li key={c.clave} className="flex items-start gap-2.5 py-2.5 first:pt-0 last:pb-0">
            <Icono estado={c.estado} />
            <div className="min-w-0">
              <p className="font-medium text-panel-text">
                {c.etiqueta} <span className="sr-only">: {TEXTO[c.estado]}</span>
              </p>
              <p className="text-xs text-panel-muted">{c.detalle}</p>
            </div>
          </li>
        ))}
      </ul>
    </div>
  );
}
