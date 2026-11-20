import Link from 'next/link';

const VISTAS = [
  { id: 'comprobantes', etiqueta: 'Comprobantes recientes', href: '/admin/academico/pagos' },
  { id: 'deudores', etiqueta: 'Quién debe', href: '/admin/academico/pagos/deudores' },
] as const;

/** Cambia entre las dos vistas de Pagos: lo que se pagó (comprobantes) y lo que falta cobrar (deudores). */
export function PagosVistas({ activa }: { activa: (typeof VISTAS)[number]['id'] }) {
  return (
    <nav aria-label="Vistas de pagos" className="inline-flex rounded-xl border border-panel-border bg-panel-surface p-1 shadow-sm">
      {VISTAS.map((v) => (
        <Link
          key={v.id}
          href={v.href}
          aria-current={v.id === activa ? 'page' : undefined}
          className={`rounded-lg px-4 py-2 text-sm font-semibold transition ${v.id === activa ? 'bg-academico-700 text-white' : 'text-panel-muted hover:bg-panel-bg hover:text-panel-text'}`}
        >
          {v.etiqueta}
        </Link>
      ))}
    </nav>
  );
}
