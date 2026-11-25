import { CircleHelp, MessagesSquare, ShieldCheck, ThumbsUp } from 'lucide-react';
import { Seccion } from '@/components/shared/ui/tabla';
import { TarjetaKpi } from '@/components/shared/ui/tarjeta-kpi';

export interface Metricas {
  dias: number;
  consultas: number;
  conversaciones: number;
  respondidas: number;
  contingencia: number;
  sin_dato: number;
  intenciones: { nombre: string; total: number }[];
  /** `null` mientras la base no tenga la columna de votos. */
  votos: { utiles: number; no_utiles: number } | null;
  separa_canal: boolean;
}

const porcentaje = (parte: number, total: number) => (total === 0 ? '—' : `${Math.round((parte / total) * 100)}%`);

/**
 * Cifras del asistente en los últimos días. Las barras por tema son decorativas (`aria-hidden`): el nombre y el total
 * de cada una están escritos como texto.
 */
export function MetricasAsistente({ metricas, etiqueta }: { metricas: Metricas; etiqueta: (nombre: string) => string }) {
  const { votos } = metricas;
  const totalVotos = votos === null ? 0 : votos.utiles + votos.no_utiles;
  const maximo = Math.max(1, ...metricas.intenciones.map((i) => i.total));

  return (
    <Seccion
      id="titulo-metricas"
      titulo="Resumen de uso"
      descripcion={
        metricas.separa_canal
          ? `Consultas del sitio público en los últimos ${metricas.dias} días. El probador no cuenta.`
          : `Consultas de los últimos ${metricas.dias} días. Incluye las del probador hasta que se actualice la base.`
      }
    >
      <div className="space-y-5">
        <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
          <TarjetaKpi
            tono="academico"
            etiqueta="Consultas"
            valor={String(metricas.consultas)}
            detalle={`En ${metricas.conversaciones} ${metricas.conversaciones === 1 ? 'conversación' : 'conversaciones'}`}
            icono={<MessagesSquare className="h-5 w-5" />}
          />
          <TarjetaKpi
            tono="academico"
            etiqueta="Respondidas por Horus AI"
            valor={porcentaje(metricas.respondidas, metricas.consultas)}
            detalle={`${metricas.contingencia} con respuesta de contingencia o privacidad`}
            icono={<ShieldCheck className="h-5 w-5" />}
          />
          <TarjetaKpi
            tono="academico"
            etiqueta="Sin dato publicado"
            valor={String(metricas.sin_dato)}
            detalle="El asistente lo dijo en vez de inventar"
            icono={<CircleHelp className="h-5 w-5" />}
          />
          <TarjetaKpi
            tono="academico"
            etiqueta="Votos útiles"
            valor={votos === null ? '—' : porcentaje(votos.utiles, totalVotos)}
            detalle={
              votos === null
                ? 'Disponible al actualizar la base'
                : totalVotos === 0
                  ? 'Todavía no hay votos'
                  : `${votos.utiles} útiles y ${votos.no_utiles} no útiles`
            }
            icono={<ThumbsUp className="h-5 w-5" />}
          />
        </div>

        {metricas.intenciones.length > 0 ? (
          <div>
            <h3 className="mb-3 text-sm font-semibold text-panel-text">Temas más consultados</h3>
            <ul className="space-y-2">
              {metricas.intenciones.map((intencion) => (
                <li key={intencion.nombre} className="grid grid-cols-[minmax(0,14rem)_1fr_3rem] items-center gap-3 text-sm">
                  <span className="truncate text-panel-text">{etiqueta(intencion.nombre)}</span>
                  <span className="h-2 overflow-hidden rounded-full bg-panel-bg" aria-hidden="true">
                    <span className="block h-full rounded-full bg-academico-600" style={{ width: `${(intencion.total / maximo) * 100}%` }} />
                  </span>
                  <span className="text-right font-semibold tabular-nums text-panel-text">{intencion.total}</span>
                </li>
              ))}
            </ul>
          </div>
        ) : null}
      </div>
    </Seccion>
  );
}
