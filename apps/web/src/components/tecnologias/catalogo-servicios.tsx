import Link from 'next/link';
import { obtenerServicios } from '@/lib/horus-api';

/**
 * Catálogo de fichas de servicios autorizadas en Laravel (equivale a la sección «catálogo de servicios» que Blade
 * mostraba en Soporte y mantenimiento). Si el catálogo está apagado no pinta nada; si Laravel no responde, avisa y
 * ofrece contacto. No se inventa contenido: solo lo que Laravel devuelve.
 */
export async function CatalogoServicios({ pagina }: { pagina: number }) {
  const resultado = await obtenerServicios(pagina);
  if (resultado.ok && !resultado.data.habilitado) return null;

  const fallo = !resultado.ok;
  const data = resultado.ok ? resultado.data : null;
  const total = data?.total ?? 0;
  const porPagina = data?.por_pagina ?? 12;
  const totalPaginas = Math.max(1, Math.ceil(total / porPagina));

  return (
    <section id="catalogo-servicios" aria-labelledby="titulo-catalogo-servicios" className="bg-white py-16 sm:py-24">
      <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
        <div className="text-center mb-12">
          <h2 id="titulo-catalogo-servicios" className="relative inline-block pb-6 text-3xl sm:text-4xl font-bold tracking-tight text-[#0A2540]">
            Catálogo de servicios tecnológicos
            <span className="absolute bottom-0 left-1/2 -translate-x-1/2 h-1 w-48 rounded-full animate-line-shine"></span>
          </h2>
        </div>

        {fallo && <p className="text-center text-slate-600">No podemos mostrar los servicios publicados en este momento. Puede contactarnos para más información.</p>}
        {!fallo && total === 0 && <p className="text-center text-slate-600">No hay fichas de servicios publicadas vigentes.</p>}
        {data && total > 0 && data.servicios.length === 0 && <p className="text-center text-slate-600">No hay fichas disponibles en esta página.</p>}

        {data && data.servicios.length > 0 && (
          <div className="grid gap-8 sm:grid-cols-2 lg:grid-cols-3">
            {data.servicios.map((servicio, i) => (
              <article
                key={`${servicio.titulo}-${i}`}
                className="min-w-0 rounded-2xl bg-white border border-stone-200 p-7 shadow-[0_4px_20px_rgba(10,37,64,0.08)] flex flex-col gap-3 [overflow-wrap:anywhere]"
              >
                <h3 className="text-xl font-bold text-[#0A2540]">{servicio.titulo}</h3>
                <p className="text-sm sm:text-base text-slate-700">{servicio.resumen}</p>
                {servicio.categoria && <p className="text-xs font-semibold uppercase tracking-wide text-slate-500">Categoría: {servicio.categoria}</p>}
                <p className="text-sm text-slate-600 whitespace-pre-line">{servicio.descripcion}</p>
                <Link
                  href="/contactos"
                  aria-label={`Consultar servicio: ${servicio.titulo}`}
                  className="mt-auto inline-flex items-center min-h-[44px] font-semibold text-[#0A2540] underline"
                >
                  Consultar servicio
                </Link>
              </article>
            ))}
          </div>
        )}

        {data && total > porPagina && (
          <nav aria-label="Páginas del catálogo de servicios" className="flex flex-wrap items-center justify-center gap-6 mt-10">
            {pagina > 1 && (
              <Link rel="prev" href={`?servicios_pagina=${pagina - 1}#catalogo-servicios`} className="inline-flex items-center min-h-[44px] underline">
                Página anterior
              </Link>
            )}
            <span className="text-sm text-slate-600">
              Página {pagina} de {totalPaginas}
            </span>
            {pagina < totalPaginas && (
              <Link rel="next" href={`?servicios_pagina=${pagina + 1}#catalogo-servicios`} className="inline-flex items-center min-h-[44px] underline">
                Página siguiente
              </Link>
            )}
          </nav>
        )}

        {(fallo || total === 0) && (
          <div className="mt-8 text-center">
            <Link href="/contactos" className="inline-flex items-center min-h-[44px] font-semibold text-[#0A2540] underline">
              Contactar con Horus Group
            </Link>
          </div>
        )}
      </div>
    </section>
  );
}
