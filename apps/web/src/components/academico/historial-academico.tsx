import type { EventoHistorialBasico } from '@/features/academico/tipos';
import { ETIQUETAS } from '@/lib/panel/formato';

const FECHA_HORA = new Intl.DateTimeFormat('es-PE', { dateStyle: 'medium', timeStyle: 'short', timeZone: 'America/Lima' });

type Entidad = 'estudiante' | 'curso';

const ACCIONES: Record<Entidad, Record<string, string>> = { estudiante: ETIQUETAS.accionEstudiante, curso: ETIQUETAS.accionCurso };
const CAMPOS: Record<Entidad, Record<string, string>> = { estudiante: ETIQUETAS.campoEstudiante, curso: ETIQUETAS.campoCurso };
const ESTADOS: Record<Entidad, Record<string, string>> = { estudiante: ETIQUETAS.estadoEstudiante, curso: ETIQUETAS.estadoCurso };

/**
 * Línea de tiempo del historial de un estudiante o un curso. Lo que llega del servidor es auditoría minimizada:
 * acción, fecha, autor, nombres de campo y transición de estado; nunca valores personales ni IP.
 */
export function HistorialAcademico({ entidad, eventos }: { entidad: Entidad; eventos: EventoHistorialBasico[] }) {
  if (eventos.length === 0) return <p className="py-8 text-center text-sm text-panel-muted">Sin operaciones registradas.</p>;

  const estado = (valor: string) => ESTADOS[entidad][valor] ?? valor;

  return (
    <ol className="relative space-y-5 border-l border-panel-border pl-5">
      {eventos.map((evento) => {
        const campos = evento.campos.map((campo) => CAMPOS[entidad][campo] ?? campo);
        return (
          <li key={evento.id} className="relative">
            <span className="absolute -left-[1.6rem] top-1.5 h-2.5 w-2.5 rounded-full border-2 border-panel-surface bg-academico-600" aria-hidden="true" />
            <p className="text-sm font-semibold text-panel-text">{ACCIONES[entidad][evento.accion] ?? evento.accion}</p>
            <p className="text-xs text-panel-muted">
              <time dateTime={evento.fecha}>{FECHA_HORA.format(new Date(evento.fecha))}</time>
              {evento.usuario ? ` · ${evento.usuario}` : ''}
            </p>
            <div className="mt-1.5 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-panel-muted">
              {evento.estado_anterior && evento.estado_nuevo ? (
                <span>{estado(evento.estado_anterior)} → {estado(evento.estado_nuevo)}</span>
              ) : null}
              {campos.length > 0 && evento.accion.endsWith('_actualizado') ? <span>Cambios en: {campos.join(', ')}</span> : null}
            </div>
          </li>
        );
      })}
    </ol>
  );
}
