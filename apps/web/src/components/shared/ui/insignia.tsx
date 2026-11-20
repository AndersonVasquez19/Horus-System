import type { ReactNode } from 'react';

export type ColorInsignia = 'verde' | 'ambar' | 'rojo' | 'azul' | 'gris';

/* Texto 800 sobre fondo 50: contraste ≥ 7:1 en todos los colores. */
const COLORES: Record<ColorInsignia, string> = {
  verde: 'bg-green-50 text-green-800 ring-green-600/20',
  ambar: 'bg-amber-50 text-amber-800 ring-amber-600/20',
  rojo: 'bg-red-50 text-red-800 ring-red-600/20',
  azul: 'bg-blue-50 text-blue-800 ring-blue-600/20',
  gris: 'bg-slate-100 text-slate-700 ring-slate-500/20',
};

export function Insignia({ color = 'gris', children }: { color?: ColorInsignia; children: ReactNode }) {
  return (
    <span className={`inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium ring-1 ring-inset ${COLORES[color]}`}>
      {children}
    </span>
  );
}

/** Color por estado conocido; los desconocidos, en gris. */
export function colorEstado(estado: string | null | undefined): ColorInsignia {
  switch (estado) {
    case 'activo':
    case 'pagada':
    case 'aprobado':
    case 'publicada':
    case 'calificado':
    case 'convertido':
    case 'ganada':
    case 'completado':
    case 'resuelto':
    case 'abierta':
      return 'verde';
    case 'pendiente':
    case 'inactivo':
    case 'borrador':
    case 'recibida':
    case 'contactado':
    case 'propuesta':
    case 'negociacion':
    case 'pausado':
    case 'en_espera_cliente':
    case 'alta':
      return 'ambar';
    case 'en_revision':
    case 'nuevo':
    case 'nueva':
    case 'calificada':
    case 'planificado':
    case 'en_curso':
    case 'abierto':
    case 'en_progreso':
    case 'media':
      return 'azul';
    case 'anulada':
    case 'rechazado':
    case 'descartado':
    case 'perdida':
    case 'cancelado':
    case 'cancelada':
    case 'critica':
      return 'rojo';
    default:
      return 'gris';
  }
}
