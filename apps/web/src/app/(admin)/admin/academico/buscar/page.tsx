import Link from 'next/link';
import { Suspense } from 'react';
import { FormularioBusqueda } from '@/components/academico/formulario-busqueda';
import { EncabezadoPagina } from '@/components/shared/ui/encabezado-pagina';
import { EstadoError, EstadoProhibido } from '@/components/shared/ui/estados';
import type { ResultadoBusqueda } from '@/features/academico/tipos';
import { consultarPanel } from '@/lib/panel/api-servidor';

export const metadata = { title: 'Buscar · Gestión académica' };

const RUTA = '/admin/academico/buscar';

type Parametros = Record<string, string | string[] | undefined>;

export default function BuscarPage({ searchParams }: { searchParams: Promise<Parametros> }) {
  return (
    <>
      <EncabezadoPagina
        titulo="Buscar"
        descripcion="Estudiantes, matrículas y ediciones, por DNI, nombre, código o número."
        migas={[{ etiqueta: 'Inicio', href: '/admin/academico' }, { etiqueta: 'Buscar' }]}
      />
      <Suspense fallback={<div className="h-40 animate-pulse rounded-xl border border-panel-border bg-panel-surface" aria-busy="true" aria-label="Buscando" />}>
        <Resultados searchParams={searchParams} />
      </Suspense>
    </>
  );
}

const GRUPOS: {
  clave: keyof ResultadoBusqueda['grupos'];
  titulo: string;
  enlace: (r: Record<string, string | number>) => string;
  listado: (q: string) => string;
}[] = [
  {
    clave: 'estudiantes',
    titulo: 'Estudiantes',
    enlace: (r) => `/admin/academico/estudiantes/${r.id}`,
    listado: (q) => `/admin/academico/estudiantes?busqueda=${encodeURIComponent(q)}`,
  },
  {
    clave: 'matriculas',
    titulo: 'Matrículas',
    enlace: (r) => `/admin/academico/matriculas/${r.id}`,
    listado: (q) => `/admin/academico/matriculas?busqueda=${encodeURIComponent(q)}`,
  },
  {
    clave: 'ediciones',
    titulo: 'Ediciones',
    enlace: (r) => `/admin/academico/convocatorias/${r.identificador}`,
    listado: (q) => `/admin/academico/convocatorias?busqueda=${encodeURIComponent(q)}`,
  },
];

async function Resultados({ searchParams }: { searchParams: Promise<Parametros> }) {
  const parametros = await searchParams;
  const q = (typeof parametros.q === 'string' ? parametros.q : '').trim().slice(0, 100);

  if (q.length < 2) {
    return (
      <div className="space-y-4">
        <FormularioBusqueda valor={q} />
        <p className="text-sm text-panel-muted">{q.length === 0 ? 'Escribe al menos 2 caracteres para buscar.' : 'Escribe al menos 2 caracteres.'}</p>
      </div>
    );
  }

  const resultado = await consultarPanel<{ data: ResultadoBusqueda }>(`academico/busqueda?q=${encodeURIComponent(q)}`, `${RUTA}?q=${encodeURIComponent(q)}`);
  if (resultado.estado === 'prohibido') return <EstadoProhibido volver="/admin/academico" />;
  if (resultado.estado !== 'ok') return <EstadoError texto="Inténtalo de nuevo en unos minutos." />;

  const { grupos } = resultado.datos.data;
  const conResultados = GRUPOS.filter((g) => (grupos[g.clave]?.length ?? 0) > 0);

  return (
    <div className="space-y-6">
      <FormularioBusqueda valor={q} />
      {Object.keys(grupos).length === 0 ? (
        <p className="text-sm text-panel-muted">Tu cuenta no tiene permisos para buscar en ningún módulo.</p>
      ) : conResultados.length === 0 ? (
        <p className="rounded-xl border border-panel-border bg-panel-surface p-6 text-sm text-panel-muted">Nada coincide con «{q}». Prueba con el DNI completo, un apellido o el código de la edición.</p>
      ) : (
        conResultados.map((g) => (
          <section key={g.clave} aria-labelledby={`grupo-${g.clave}`} className="overflow-hidden rounded-xl border border-panel-border bg-panel-surface shadow-sm">
            <div className="flex items-center justify-between gap-2 border-b border-panel-border px-4 py-3">
              <h2 id={`grupo-${g.clave}`} className="font-heading text-sm font-semibold text-panel-text">{g.titulo}</h2>
              <Link href={g.listado(q)} className="text-xs font-semibold text-academico-700 hover:underline">
                Ver todos
              </Link>
            </div>
            <ul className="divide-y divide-panel-border">
              {grupos[g.clave]?.map((r, k) => (
                <li key={`${g.clave}-${k}`}>
                  <Link href={g.enlace(r)} className="flex flex-col gap-0.5 px-4 py-3 hover:bg-academico-50/50 sm:flex-row sm:items-center sm:justify-between">
                    <span className="font-semibold text-panel-text">{r.texto}</span>
                    <span className="text-sm text-panel-muted">{r.detalle}</span>
                  </Link>
                </li>
              ))}
            </ul>
          </section>
        ))
      )}
      <p className="text-xs text-panel-muted">Se muestran hasta 5 resultados por grupo.</p>
    </div>
  );
}
