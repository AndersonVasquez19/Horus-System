import type { Metadata } from 'next';
import Image from 'next/image';
import { Settings, Wrench, GraduationCap, Check, ArrowRight } from 'lucide-react';
import { SOPORTE_SERVICIOS } from '@/data/servicios';
import { CONTACT_INFO } from '@/data/contactos';

export const metadata: Metadata = {
  title: 'Soporte y Mantenimiento',
  description: 'Mantenimiento preventivo, correctivo y configuración de equipos informáticos y TI en Cajamarca.',
};

export default function SoporteMantenimientoPage() {
  const getIcon = (id: string) => {
    switch (id) {
      case 'sop-preventivo':
        return <Wrench className="h-8 w-8 text-white" />;
      case 'sop-asesoria':
        return <GraduationCap className="h-8 w-8 text-white" />;
      default:
        return <Settings className="h-8 w-8 text-white" />;
    }
  };

  return (
    <div className="pt-20">
      {/* Intro Soporte y Mantenimiento con degradado institucional y lineShine */}
      <section className="relative overflow-hidden py-16 sm:py-24 bg-gradient-to-br from-[#0A2540] via-[#102d49] to-[#2563A8] text-white">
        <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
          <div className="grid gap-10 lg:grid-cols-2 items-center">
            <div className="max-w-xl">
              <h2 className="relative pb-6 text-3xl sm:text-5xl font-bold tracking-tight text-white leading-tight">
                SOPORTE Y MANTENIMIENTO
                <span className="absolute bottom-0 left-0 h-1 w-48 rounded-full animate-line-shine"></span>
              </h2>
              <p className="mt-6 text-base sm:text-lg text-white/90 leading-relaxed text-justify">
                Contamos con un gran equipo de trabajo en TI donde te ofrecemos soluciones físicas (hardware) o
                lógicas (software) de PC, sistemas y accesorios.
              </p>
              <p className="mt-4 text-base sm:text-lg text-white/90 leading-relaxed text-justify">
                Te ofrecemos mantenimiento preventivo y correctivo en tus equipos informáticos donde te ayuden a
                seguir con la productividad de estos.
              </p>
            </div>

            <div className="relative flex justify-center items-center">
              <div className="relative h-64 sm:h-80 w-full max-w-[450px] drop-shadow-[0_20px_60px_rgba(0,0,0,0.4)] hover:-translate-y-2 hover:scale-105 transition-all duration-400">
                <Image
                  src="/images/soporte-mantenimiento/img-intro.png"
                  alt="Soporte y mantenimiento"
                  fill
                  priority
                  sizes="(max-width: 1024px) 100vw, 450px"
                  className="object-contain"
                />
              </div>
            </div>
          </div>
        </div>
      </section>

      {/* Nuestros Servicios en fondo degradado claro exacto de Blade */}
      <section className="bg-gradient-to-b from-white to-[#f8f9fa] py-16 sm:py-24">
        <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
          <div className="text-center mb-16">
            <h2 className="relative inline-block pb-6 text-3xl sm:text-4xl md:text-5xl font-bold tracking-tight text-[#0A2540]">
              Nuestros Servicios
              <span className="absolute bottom-0 left-1/2 -translate-x-1/2 h-1 w-48 rounded-full animate-line-shine"></span>
            </h2>
          </div>

          <div className="grid gap-8 sm:grid-cols-2 lg:grid-cols-3">
            {SOPORTE_SERVICIOS.map((item) => (
              <article
                key={item.id}
                className="card-hover-border rounded-2xl bg-white border border-stone-200 p-8 sm:p-9 shadow-[0_8px_30px_rgba(0,0,0,0.1)] hover:-translate-y-3 hover:shadow-[0_15px_50px_rgba(0,0,0,0.18)] hover:border-gold transition-all duration-400 flex flex-col justify-between"
              >
                <div>
                  {/* Circular Icon */}
                  <div className="flex h-20 w-20 items-center justify-center rounded-full bg-gradient-to-br from-[#0A2540] to-gold shadow-[0_8px_25px_rgba(10,37,64,0.2)] mx-auto mb-6">
                    {getIcon(item.id)}
                  </div>

                  <h3 className="text-xl font-bold text-[#0A2540] text-center mb-4 leading-snug">
                    {item.titulo}
                  </h3>

                  <p className="text-sm sm:text-base text-slate-600 leading-relaxed mb-6 text-center">
                    {item.descripcion}
                  </p>

                  <ul className="space-y-3 border-t-2 border-stone-100 pt-5 mb-8">
                    {item.items.map((bullet, i) => (
                      <li key={i} className="flex items-start gap-2.5 text-xs sm:text-sm text-slate-700 font-medium">
                        <Check className="h-4 w-4 text-gold shrink-0 mt-0.5" />
                        <span>{bullet}</span>
                      </li>
                    ))}
                  </ul>
                </div>

                <div>
                  <a
                    href={`https://wa.me/${CONTACT_INFO.phoneClean}?text=${encodeURIComponent(`Hola, deseo solicitar cotización para: ${item.servicioCotizar}.`)}`}
                    target="_blank"
                    rel="noopener noreferrer"
                    className="inline-flex items-center justify-center gap-3 w-full min-h-[48px] rounded-xl border-2 border-[#0A2540] bg-[#0A2540] px-5 py-3 text-sm font-semibold text-white hover:bg-gold hover:text-[#0A2540] hover:border-gold transition-colors duration-200"
                    aria-label={`Solicitar cotización de ${item.titulo}`}
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
