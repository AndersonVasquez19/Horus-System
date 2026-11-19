import Link from 'next/link';

const PESTANAS = [
  { id: 'sedes', etiqueta: 'Sedes', href: '/admin/academico/infraestructura/sedes' },
  { id: 'aulas', etiqueta: 'Aulas', href: '/admin/academico/infraestructura/aulas' },
  { id: 'plataformas', etiqueta: 'Plataformas virtuales', href: '/admin/academico/infraestructura/plataformas' },
] as const;

/** Navegación entre los tres catálogos de infraestructura (Server Component: la activa llega por prop). */
export function PestanasInfraestructura({ activa }: { activa: (typeof PESTANAS)[number]['id'] }) {
  return (
    <nav aria-label="Infraestructura académica" className="mb-4 flex flex-wrap gap-1 border-b border-panel-border">
      {PESTANAS.map((pestana) => (
        <Link
          key={pestana.id}
          href={pestana.href}
          aria-current={pestana.id === activa ? 'page' : undefined}
          className={`-mb-px rounded-t-lg border-b-2 px-4 py-2 text-sm font-semibold transition ${
            pestana.id === activa ? 'border-academico-700 text-academico-800' : 'border-transparent text-panel-muted hover:text-panel-text'
          }`}
        >
          {pestana.etiqueta}
        </Link>
      ))}
    </nav>
  );
}
