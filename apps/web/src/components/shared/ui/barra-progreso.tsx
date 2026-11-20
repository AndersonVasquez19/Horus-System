export interface SegmentoProgreso {
  etiqueta: string;
  /** Cantidad que representa el segmento (se compara con `total`). */
  valor: number;
  /** Texto ya formateado para la leyenda (p. ej. `S/ 350.00`). */
  texto: string;
  /** Clase de color de fondo del segmento. Completa, para que Tailwind la detecte. */
  clase: string;
}

interface BarraProgresoProps {
  titulo: string;
  /** Frase grande sobre la barra (p. ej. «70 % cobrado»). */
  resumen: string;
  segmentos: readonly SegmentoProgreso[];
  /** Lo que representa el 100 % de la barra. */
  total: number;
}

const porcentaje = (valor: number, total: number) => (total > 0 ? Math.min(100, Math.max(0, (valor / total) * 100)) : 0);
const entero = (n: number) => `${Math.round(n)} %`;

/**
 * Barra de progreso apilada sin dependencias. La barra es una imagen decorativa con descripción (`role="img"`); las
 * mismas cifras están en la leyenda como texto, así que no hay información que dependa solo del color.
 */
export function BarraProgreso({ titulo, resumen, segmentos, total }: BarraProgresoProps) {
  const descripcion = `${titulo}: ${segmentos.map((s) => `${s.etiqueta} ${entero(porcentaje(s.valor, total))}`).join(', ')}`;

  return (
    <figure className="rounded-xl border border-panel-border bg-panel-surface p-5 shadow-sm">
      <figcaption className="text-sm font-semibold text-panel-muted">{titulo}</figcaption>
      <p className="mt-1 font-heading text-2xl font-bold text-panel-text">{resumen}</p>
      <div role="img" aria-label={descripcion} className="mt-3 flex h-3 overflow-hidden rounded-full bg-panel-bg">
        {segmentos.map((s) =>
          s.valor > 0 ? <div key={s.etiqueta} className={s.clase} style={{ width: `${porcentaje(s.valor, total)}%` }} /> : null,
        )}
      </div>
      <ul className="mt-3 flex flex-wrap gap-x-5 gap-y-1 text-sm text-panel-text">
        {segmentos.map((s) => (
          <li key={s.etiqueta} className="flex items-center gap-2">
            <span className={`inline-block h-2.5 w-2.5 rounded-full ${s.clase}`} aria-hidden="true" />
            <span>
              {s.etiqueta}: <strong className="font-semibold">{s.texto}</strong>
            </span>
          </li>
        ))}
      </ul>
    </figure>
  );
}
