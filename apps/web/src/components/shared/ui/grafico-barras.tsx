import type { TonoUi } from './boton';

const BARRA: Record<TonoUi, string> = {
  academico: 'bg-academico-600',
  servicios: 'bg-servicios-600',
};

interface GraficoBarrasProps {
  tono: TonoUi;
  titulo: string;
  datos: { etiqueta: string; total: number }[];
}

/**
 * Gráfico de barras sin dependencias. Las barras son decorativas (`aria-hidden`): la misma información se ofrece
 * como tabla para lectores de pantalla.
 */
export function GraficoBarras({ tono, titulo, datos }: GraficoBarrasProps) {
  const maximo = Math.max(1, ...datos.map((dato) => dato.total));

  return (
    <figure>
      <figcaption className="sr-only">{titulo}</figcaption>
      <div className="flex h-48 items-end gap-3" aria-hidden="true">
        {datos.map((dato) => (
          <div key={dato.etiqueta} className="flex h-full flex-1 flex-col items-center justify-end gap-2">
            <span className="text-xs font-semibold text-panel-text">{dato.total}</span>
            <div
              className={`w-full max-w-12 rounded-t-md ${BARRA[tono]} transition-all`}
              style={{ height: `${dato.total === 0 ? 2 : Math.max(6, (dato.total / maximo) * 100)}%` }}
            />
            <span className="text-xs text-panel-muted">{dato.etiqueta}</span>
          </div>
        ))}
      </div>
      <table className="sr-only">
        <caption>{titulo}</caption>
        <thead>
          <tr>
            <th scope="col">Mes</th>
            <th scope="col">Total</th>
          </tr>
        </thead>
        <tbody>
          {datos.map((dato) => (
            <tr key={dato.etiqueta}>
              <td>{dato.etiqueta}</td>
              <td>{dato.total}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </figure>
  );
}
