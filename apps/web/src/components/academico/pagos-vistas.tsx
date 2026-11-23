import Link from 'next/link';

const VISTAS = [
  { id: 'deudores', etiqueta: 'Pendientes y deudores', href: '/admin/academico/pagos' },
  { id: 'completos', etiqueta: 'Pagos completos', href: '/admin/academico/pagos/completos' },
  { id: 'comprobantes', etiqueta: 'Comprobantes', href: '/admin/academico/pagos/comprobantes' },
] as const;

/** Navegación clara en 3 tablas separadas: deudas/pendientes, pagos completos y comprobantes subidos. */
export function PagosVistas({ activa }: { activa: (typeof VISTAS)[number]['id'] }) {
  return (
    <nav aria-label="Vistas de pagos" className="inline-flex flex-wrap gap-1 rounded-xl border border-panel-border bg-panel-surface p-1 shadow-sm">
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
