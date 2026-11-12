import type { Metadata } from 'next';
import Image from 'next/image';
import { Zap, ArrowRight } from 'lucide-react';
import { CABLEADO_CATEGORIAS } from '@/data/servicios';
import { CONTACT_INFO } from '@/data/contactos';

export const metadata: Metadata = {
  title: 'Cableado Estructurado',
  description: 'Diseño e implementación de sistemas de cableado estructurado UTP/STP y redes LAN en Cajamarca.',
};

export default function CableadoEstructuradoPage() {
  return (
    <div className="pt-20">
      {/* Intro Cableado con degradado institucional y lineShine */}
      <section className="relative overflow-hidden py-16 sm:py-24 bg-gradient-to-br from-[#0A2540] via-[#102d49] to-[#2563A8] text-white">
        <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
          <div className="grid gap-10 lg:grid-cols-2 items-center">
            <div className="max-w-xl">
              <h2 className="relative pb-6 text-3xl sm:text-5xl font-bold tracking-tight text-white leading-tight">
                CABLEADO ESTRUCTURADO
                <span className="absolute bottom-0 left-0 h-1 w-48 rounded-full animate-line-shine"></span>
              </h2>
              <p className="mt-6 text-base sm:text-lg text-white/90 leading-relaxed text-justify">
                Diseñamos e implementamos sistemas de cableado estructurado (UTP/STP) y redes LAN bajo estrictas
                normas técnicas para garantizar una interconectividad eficiente y de alta velocidad entre todas
                las áreas de su empresa.
              </p>
            </div>

            <div className="relative flex justify-center items-center">
              <div className="relative h-72 sm:h-96 w-full max-w-[540px] rounded-2xl overflow-hidden border-2 border-gold/40 shadow-2xl hover:-translate-y-2 hover:border-gold transition-all duration-400">
                <Image
                  src="/images/tecnologias/cableado.jpg"
                  alt="Instalación de cableado"
                  fill
                  priority
                  sizes="(max-width: 1024px) 100vw, 50vw"
                  className="object-cover"
                />
              </div>
            </div>
          </div>
        </div>
      </section>

      {/* Categorías de Cableado en fondo blanco exacto de Blade */}
      <section className="bg-white py-16 sm:py-24">
        <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
          <div className="text-center mb-16">
            <h1 className="relative inline-block pb-6 text-3xl sm:text-4xl md:text-5xl font-bold tracking-tight text-[#0A2540]">
              CATEGORÍAS DE CABLEADO
              <span className="absolute bottom-0 left-1/2 -translate-x-1/2 h-1 w-48 rounded-full animate-line-shine"></span>
            </h1>
          </div>

          <div className="grid gap-8 sm:grid-cols-2 lg:grid-cols-3">
            {CABLEADO_CATEGORIAS.map((cat) => (
              <article
                key={cat.id}
                className="card-hover-border rounded-2xl bg-white border border-stone-200 shadow-[0_4px_20px_rgba(10,37,64,0.08)] hover:-translate-y-2 hover:shadow-[0_20px_50px_rgba(10,37,64,0.14)] hover:border-gold/60 transition-all duration-350 flex flex-col justify-between overflow-hidden"
              >
                <div>
                  <div className="relative h-56 w-full bg-stone-100 overflow-hidden">
                    <Image
                      src={cat.imagen}
                      alt={cat.titulo}
                      fill
                      sizes="(max-width: 768px) 100vw, 400px"
                      className="object-cover hover:scale-105 transition-transform duration-500"
                    />
                  </div>

                  <div className="p-7">
                    <h3 className="text-2xl font-bold text-[#0A2540]">{cat.titulo}</h3>
                    <div className="mt-3 mb-4 h-0.5 w-12 bg-gold"></div>
                    <p className="text-sm sm:text-base text-slate-600 leading-relaxed mb-5">
                      {cat.descripcion}
                    </p>

                    {cat.especificaciones && (
                      <div className="inline-flex items-center gap-2 text-xs sm:text-sm font-semibold text-slate-800 bg-stone-100 border border-stone-200 rounded-xl px-3.5 py-2">
                        <Zap className="h-4 w-4 text-gold flex-shrink-0" />
                        <span>{cat.especificaciones[0]}</span>
                      </div>
                    )}
                  </div>
                </div>

                <div className="p-7 pt-0">
                  <a
                    href={`https://wa.me/${CONTACT_INFO.phoneClean}?text=${encodeURIComponent(`Hola, deseo solicitar cotización para: ${cat.servicioCotizar}.`)}`}
                    target="_blank"
                    rel="noopener noreferrer"
                    className="inline-flex items-center justify-center gap-3 w-full min-h-[48px] rounded-xl border-2 border-[#0A2540] bg-[#0A2540] px-5 py-3 text-sm font-semibold text-white hover:bg-gold hover:text-[#0A2540] hover:border-gold transition-colors duration-200"
                    aria-label={`Solicitar cotización de ${cat.titulo}`}
                  >
                    <span>Solicitar cotización</span>
                    <ArrowRight className="h-4 w-4" />
                  </a>
                </div>
              </article>
            ))}
          </div>
        </div>
      </section>
    </div>
  );
}
