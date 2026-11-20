import Image from 'next/image';

/** Logo oficial de Horus (el mismo del sitio y del login de Laravel). Decorativo: el nombre va siempre en texto. */
export function LogoHorus({ className = '', prioridad = false }: { className?: string; prioridad?: boolean }) {
  return (
    <Image
      src="/images/index/logos/logo-horus.png"
      alt=""
      width={588}
      height={425}
      priority={prioridad}
      className={className}
    />
  );
}

/** Marca compacta para el riel lateral del panel. */
export function MarcaHorus({ subtitulo }: { subtitulo: string }) {
  return (
    <div className="flex items-center gap-3">
      <LogoHorus className="h-9 w-auto shrink-0" prioridad />
      <div className="min-w-0 leading-tight">
        <p className="font-heading text-sm font-bold tracking-[0.14em] text-white">HORUS GROUP</p>
        <p className="mt-0.5 truncate text-xs text-white/65">{subtitulo}</p>
      </div>
    </div>
  );
}
