interface TextoPlegableProps {
  texto: string;
  /** Más caracteres que esto se recortan a unas líneas con «Ver completo». */
  limite?: number;
  className?: string;
}

/**
 * Texto largo en una celda: recortado a tres líneas con un control «Ver completo» (`<details>` nativo, con teclado y
 * lector de pantalla sin JavaScript). Los textos cortos se muestran enteros y sin control.
 */
export function TextoPlegable({ texto, limite = 180, className = '' }: TextoPlegableProps) {
  if (texto.length <= limite) return <p className={`whitespace-pre-line ${className}`}>{texto}</p>;

  return (
    <details className={`group ${className}`}>
      <summary className="cursor-pointer list-none rounded outline-offset-2 focus-visible:outline-2 focus-visible:outline-academico-700 [&::-webkit-details-marker]:hidden">
        <span className="line-clamp-3 whitespace-pre-line group-open:hidden">{texto}</span>
        <span className="hidden whitespace-pre-line group-open:inline">{texto}</span>
        <span className="mt-1 block text-xs font-semibold text-academico-700 underline underline-offset-2">
          <span className="group-open:hidden">Ver completo</span>
          <span className="hidden group-open:inline">Ver menos</span>
        </span>
      </summary>
    </details>
  );
}
