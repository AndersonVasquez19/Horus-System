'use client';

import { useState, useEffect, useRef, useCallback } from 'react';
import Image from 'next/image';
import {
  ChevronLeft,
  ChevronRight,
  Play,
  Pause,
  ArrowRight,
  X,
  Award,
  CheckCircle2,
} from 'lucide-react';
import { CONVENIOS, type Convenio } from '@/data/convenios';

export function ConveniosSection({
  title = 'Convenios estratégicos',
  intro = 'Alianzas con colegios profesionales e instituciones que respaldan la validez de nuestras certificaciones a nivel nacional.',
}: {
  title?: string;
  intro?: string;
}) {
  const [activeConvenio, setActiveConvenio] = useState<Convenio | null>(null);
  const [currentIndex, setCurrentIndex] = useState(0);
  const [isPaused, setIsPaused] = useState(false);
  const trackRef = useRef<HTMLDivElement>(null);

  const total = CONVENIOS.length;
  // Responsive visible count: 3 on desktop, 2 on tablet, 1 on mobile
  const [visibleCount, setVisibleCount] = useState(3);

  useEffect(() => {
    const handleResize = () => {
      if (window.innerWidth < 768) setVisibleCount(1);
      else if (window.innerWidth < 1024) setVisibleCount(2);
      else setVisibleCount(3);
    };
    handleResize();
    window.addEventListener('resize', handleResize);
    return () => window.removeEventListener('resize', handleResize);
  }, []);

  const maxIndex = Math.max(0, total - visibleCount);

  const nextSlide = useCallback(() => {
    setCurrentIndex((prev) => (prev >= maxIndex ? 0 : prev + 1));
  }, [maxIndex]);

  const prevSlide = useCallback(() => {
    setCurrentIndex((prev) => (prev <= 0 ? maxIndex : prev - 1));
  }, [maxIndex]);

  // Autoplay
  useEffect(() => {
    if (isPaused) return;
    const timer = setInterval(() => {
      nextSlide();
    }, 6000);
    return () => clearInterval(timer);
  }, [isPaused, nextSlide]);

  // Scroll track when currentIndex changes
  useEffect(() => {
    if (trackRef.current) {
      const cardWidth = trackRef.current.scrollWidth / total;
      trackRef.current.scrollTo({
        left: currentIndex * cardWidth,
        behavior: 'smooth',
      });
    }
  }, [currentIndex, total]);

  // Handle ESC for modal
  useEffect(() => {
    const handleKeyDown = (e: KeyboardEvent) => {
      if (e.key === 'Escape') setActiveConvenio(null);
    };
    if (activeConvenio) {
      document.body.style.overflow = 'hidden';
      window.addEventListener('keydown', handleKeyDown);
    } else {
      document.body.style.overflow = 'unset';
    }
    return () => {
      document.body.style.overflow = 'unset';
      window.removeEventListener('keydown', handleKeyDown);
    };
  }, [activeConvenio]);

  const rangeText = `${currentIndex + 1}–${Math.min(total, currentIndex + visibleCount)} de ${total}`;

  return (
    <section id="convenios" className="scroll-mt-6 py-16 md:py-20 bg-gradient-to-b from-[#0A2540] to-[#0A1929]">
      <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
        {/* Header con título y controles exactos de Blade */}
        <div className="flex flex-wrap items-end justify-between gap-6 mb-10">
          <div>
            <h2 className="text-2xl sm:text-3xl md:text-4xl font-bold tracking-tight text-white leading-tight">
              {title}
            </h2>
            <div className="mt-4 h-[3px] w-14 rounded-full bg-gold"></div>
            {intro && (
              <p className="mt-4 max-w-xl text-sm sm:text-base leading-relaxed text-white/80">
                {intro}
              </p>
            )}
          </div>

          <div className="flex items-center gap-2.5 self-start md:self-auto">
            <span className="min-w-[5.5ch] text-right text-xs sm:text-sm font-semibold text-white/80 font-mono mr-1">
              {rangeText}
            </span>

            <button
              type="button"
              onClick={prevSlide}
              aria-label="Convenios anteriores"
              className="inline-flex h-[2.9rem] w-[2.9rem] items-center justify-center rounded-xl border border-white/20 bg-white/10 text-white hover:bg-gold hover:text-ink hover:border-gold transition-colors focus-visible:outline-3 focus-visible:outline-gold"
            >
              <ChevronLeft className="h-5 w-5" />
            </button>

            <button
              type="button"
              onClick={nextSlide}
              aria-label="Convenios siguientes"
              className="inline-flex h-[2.9rem] w-[2.9rem] items-center justify-center rounded-xl border border-white/20 bg-white/10 text-white hover:bg-gold hover:text-ink hover:border-gold transition-colors focus-visible:outline-3 focus-visible:outline-gold"
            >
              <ChevronRight className="h-5 w-5" />
            </button>

            <button
              type="button"
              onClick={() => setIsPaused(!isPaused)}
              aria-label={isPaused ? 'Reanudar rotación automática' : 'Pausar rotación automática'}
              className="inline-flex h-[2.9rem] w-[2.9rem] items-center justify-center rounded-xl border border-white/20 bg-white/10 text-white hover:bg-gold hover:text-ink hover:border-gold transition-colors focus-visible:outline-3 focus-visible:outline-gold"
            >
              {isPaused ? <Play className="h-4 w-4" /> : <Pause className="h-4 w-4" />}
            </button>
          </div>
        </div>

        {/* Carrusel Track con scroll-snap */}
        <div
          ref={trackRef}
          onMouseEnter={() => setIsPaused(true)}
          onMouseLeave={() => setIsPaused(false)}
          className="flex gap-6 overflow-x-auto scroll-smooth snap-x snap-mandatory pb-6"
          style={{ scrollbarWidth: 'none', msOverflowStyle: 'none' }}
        >
          {CONVENIOS.map((item, index) => {
            const isDim = index < currentIndex || index >= currentIndex + visibleCount;
            return (
              <div
                key={item.id}
                className={`snap-start flex-shrink-0 w-[85%] sm:w-[calc(50%-12px)] lg:w-[calc(33.333%-16px)] transition-all duration-400 ${
                  isDim ? 'opacity-50 saturate-75' : 'opacity-100'
                }`}
              >
                <article className="flex flex-col w-full h-full overflow-hidden rounded-[1.1rem] border border-white/12 bg-[#153A59] text-white text-left transition-all duration-300 hover:border-gold hover:shadow-[0_18px_44px_rgba(3,12,22,0.28)] group">
                  {/* Foto con aspect-ratio 16/10 y gradiente */}
                  {item.imagen ? (
                    <div className="relative aspect-[16/10] w-full overflow-hidden bg-[#0A2540]">
                      <Image
                        src={item.imagen}
                        alt={item.nombre}
                        fill
                        sizes="400px"
                        className="object-cover group-hover:scale-105 transition-transform duration-700"
                        style={{ objectPosition: item.posicion || 'center' }}
                      />
                      <div className="absolute inset-0 bg-gradient-to-t from-[#153A59] via-transparent to-transparent pointer-events-none"></div>
                    </div>
                  ) : (
                    <div className="relative aspect-[16/10] w-full grid place-items-center bg-gradient-to-br from-[#0A2540] to-[#153A59]">
                      <b className="relative z-10 text-3xl font-bold text-white/90">{item.siglas}</b>
                    </div>
                  )}

                  {/* Cuerpo de la tarjeta con logo elevado */}
                  <div className="flex flex-1 flex-col p-6 pt-0">
                    <div className="relative z-10 flex items-end justify-between gap-3 -mt-8 mb-4">
                      <div className="grid place-items-center w-[4.25rem] h-[4.25rem] p-2 rounded-2xl bg-white shadow-[0_8px_22px_rgba(3,12,22,0.25)] flex-none">
                        <Image
                          src={item.logo}
                          alt={`Logo ${item.siglas}`}
                          width={56}
                          height={56}
                          className="w-full h-full object-contain"
                        />
                      </div>
                      <span className="pb-1 text-xs font-bold tracking-wider text-gold">
                        {item.siglas}
                      </span>
                    </div>

                    <h3 className="text-lg font-semibold text-white leading-snug line-clamp-2 min-h-[2.8em] group-hover:text-gold transition-colors">
                      {item.nombre}
                    </h3>

                    <button
                      type="button"
                      onClick={() => setActiveConvenio(item)}
                      className="inline-flex self-start items-center gap-2 mt-5 py-1 text-sm font-semibold text-gold hover:underline underline-offset-4 group/btn"
                    >
                      <span>Ver convenio</span>
                      <ArrowRight className="h-3.5 w-3.5 transition-transform duration-200 group-hover/btn:translate-x-1" />
                    </button>
                  </div>
                </article>
              </div>
            );
          })}
        </div>
      </div>

      {/* Modal de Detalle Completo 1:1 con section.blade.php */}
      {activeConvenio && (
        <div
          onClick={(e) => {
            if (e.target === e.currentTarget) setActiveConvenio(null);
          }}
          className="fixed inset-0 z-50 flex items-center justify-center p-4 sm:p-6 bg-slate-950/75 backdrop-blur-sm animate-in fade-in duration-300"
          role="dialog"
          aria-modal="true"
          aria-labelledby="convenio-modal-title"
        >
          <div className="relative w-full max-w-2xl max-h-[90vh] flex flex-col bg-white text-slate-900 rounded-3xl shadow-2xl border border-slate-100 overflow-hidden animate-in zoom-in-95 duration-300">
            {/* Botón de Cierre */}
            <button
              type="button"
              onClick={() => setActiveConvenio(null)}
              className="absolute top-4 right-4 z-20 w-10 h-10 rounded-full bg-white/90 hover:bg-white text-slate-700 hover:text-slate-900 flex items-center justify-center shadow-md border border-slate-200/80 transition-all hover:rotate-90 focus:outline-none focus:ring-2 focus:ring-[#0A2540]"
              aria-label="Cerrar detalle del convenio"
            >
              <X className="h-5 w-5" />
            </button>

            {/* Cabecera con Foto o Banner Institucional */}
            <div className="relative w-full shrink-0 overflow-hidden">
              {activeConvenio.imagen ? (
                <div className="w-full h-56 sm:h-72 bg-slate-900 relative">
                  <Image
                    src={activeConvenio.imagen}
                    alt={activeConvenio.nombre}
                    fill
                    sizes="800px"
                    className="object-cover"
                    style={{ objectPosition: activeConvenio.posicion || 'center' }}
                  />
                  <div className="absolute inset-0 bg-gradient-to-t from-slate-950/60 via-transparent to-transparent"></div>
                </div>
              ) : (
                <div className="w-full h-44 sm:h-52 bg-gradient-to-br from-[#0A2540] via-[#102d49] to-[#153a59] flex items-center justify-center p-6 relative overflow-hidden">
                  <div className="w-20 h-20 rounded-2xl bg-white p-3 shadow-xl flex items-center justify-center">
                    <Image
                      src={activeConvenio.logo}
                      alt={activeConvenio.nombre}
                      width={64}
                      height={64}
                      className="w-full h-full object-contain"
                    />
                  </div>
                </div>
              )}
            </div>

            {/* Cuerpo Desplazable del Modal */}
            <div className="p-6 sm:p-8 overflow-y-auto space-y-4">
              <div className="flex flex-wrap items-center gap-3">
                <span className="px-3 py-1 rounded-full text-xs sm:text-sm font-bold tracking-wide bg-slate-100 text-[#0A2540] border border-slate-200">
                  {activeConvenio.siglas}
                </span>
              </div>

              <h3 id="convenio-modal-title" className="text-xl sm:text-2xl font-bold text-slate-900 leading-snug">
                {activeConvenio.nombre}
              </h3>

              {/* Garantías Destacadas */}
              <div className="bg-slate-50 rounded-2xl p-4 sm:p-5 border border-slate-200">
                <h4 className="text-sm sm:text-base font-semibold text-slate-900 mb-2.5 flex items-center gap-2">
                  <Award className="h-5 w-5 text-[#0A2540]" />
                  <span>Beneficios para participantes</span>
                </h4>
                <ul className="text-xs sm:text-sm text-slate-700 space-y-2">
                  <li className="flex items-start gap-2">
                    <CheckCircle2 className="h-4 w-4 text-[#0A2540] mt-0.5 shrink-0" />
                    <span>Certificados oficiales con firma y sello de Decanatura y representantes de Horus Group.</span>
                  </li>
                  <li className="flex items-start gap-2">
                    <CheckCircle2 className="h-4 w-4 text-[#0A2540] mt-0.5 shrink-0" />
                    <span>Validez acreditada para postulaciones y convocatorias en entidades públicas y privadas a nivel nacional.</span>
                  </li>
                </ul>
              </div>

              {/* Descripción Oficial Completa */}
              <div>
                <h4 className="text-sm sm:text-base font-semibold text-slate-900 mb-2">Alcance del convenio</h4>
                <p className="text-xs sm:text-sm leading-relaxed text-slate-600">
                  {activeConvenio.descripcion}
                </p>
              </div>

              {/* Footer del Modal */}
              <div className="pt-4 border-t border-slate-100 flex items-center justify-between">
                <div className="text-xs text-slate-500">
                  Horus Group SRL &copy; 2026 Alianzas Estratégicas
                </div>
                <button
                  type="button"
                  onClick={() => setActiveConvenio(null)}
                  className="px-5 py-2.5 rounded-xl text-sm font-semibold bg-[#0A2540] text-white hover:bg-slate-800 transition-colors shadow-sm"
                >
                  Entendido
                </button>
              </div>
            </div>
          </div>
        </div>
      )}
    </section>
  );
}
