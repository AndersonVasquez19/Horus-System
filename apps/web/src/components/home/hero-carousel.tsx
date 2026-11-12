'use client';

import { useState, useEffect, useCallback } from 'react';
import Image from 'next/image';
import Link from 'next/link';
import { ChevronLeft, ChevronRight, ArrowRight } from 'lucide-react';
import { HERO_SLIDES, HERO_STATS } from '@/data/hero-slides';

export function HeroCarousel() {
  const [current, setCurrent] = useState(0);
  const [isPaused, setIsPaused] = useState(false);

  const nextSlide = useCallback(() => {
    setCurrent((prev) => (prev + 1) % HERO_SLIDES.length);
  }, []);

  const prevSlide = useCallback(() => {
    setCurrent((prev) => (prev - 1 + HERO_SLIDES.length) % HERO_SLIDES.length);
  }, []);

  // Autoplay interval
  useEffect(() => {
    if (isPaused) return;
    const interval = setInterval(() => {
      nextSlide();
    }, 6000);
    return () => clearInterval(interval);
  }, [isPaused, nextSlide]);

  return (
    <div
      className="relative flex flex-col bg-[#0A1929] text-white pt-20"
      onMouseEnter={() => setIsPaused(true)}
      onMouseLeave={() => setIsPaused(false)}
      onFocus={() => setIsPaused(true)}
      onBlur={() => setIsPaused(false)}
      role="region"
      aria-roledescription="carrusel"
      aria-label="Servicios de Horus Group SRL"
    >
      <h1 className="sr-only">
        Horus Group SRL: tecnología, capacitaciones y soporte en Cajamarca
      </h1>

      {/* Hero Stage */}
      <div className="relative isolate flex flex-col justify-center min-h-[520px] sm:min-h-[580px] lg:min-h-[640px] py-14 lg:py-20 overflow-hidden bg-[#0A2540]">
        {/* Backdrops */}
        <div className="absolute inset-0 -z-10 overflow-hidden" aria-hidden="true">
          {HERO_SLIDES.map((slide, idx) => (
            <div
              key={idx}
              className={`absolute inset-0 transition-opacity duration-1000 ease-in-out ${
                current === idx ? 'opacity-100' : 'opacity-0 pointer-events-none'
              }`}
            >
              <Image
                src={slide.image}
                alt=""
                fill
                priority={idx === 0}
                sizes="100vw"
                className="object-cover"
                style={{ objectPosition: slide.position || 'center' }}
              />
            </div>
          ))}
          {/* El degradado azul exacto de Horus: la foto se ve limpia a color a la derecha */}
          <div className="absolute inset-0 pointer-events-none bg-gradient-to-r from-[#0A2540]/95 via-[#0A2540]/80 md:via-[#0A2540]/70 to-transparent"></div>
          <div className="absolute inset-0 pointer-events-none bg-gradient-to-t from-[#0A1929] via-transparent to-[#0A2540]/50"></div>
        </div>

        {/* Copy stack */}
        <div className="mx-auto max-w-7xl w-full px-4 sm:px-6 lg:px-8 relative z-10">
          <div className="max-w-2xl text-shadow">
            {HERO_SLIDES.map((slide, idx) => {
              if (current !== idx) return null;
              return (
                <div
                  key={idx}
                  className="animate-in fade-in slide-in-from-bottom-6 duration-600"
                  role="group"
                  aria-roledescription="diapositiva"
                  aria-label={`${idx + 1} de ${HERO_SLIDES.length}`}
                >
                  <span className="inline-flex items-center px-4 py-1.5 rounded-full border border-white/20 bg-white/10 text-xs sm:text-sm font-medium tracking-wide text-white/95 backdrop-blur-md">
                    {slide.eyebrow}
                  </span>

                  <h2 className="mt-4 mb-4 text-3xl sm:text-5xl lg:text-6xl font-bold tracking-tight text-white leading-[1.08]">
                    {slide.title}{' '}
                    <span className="block text-gold">{slide.highlight}</span>
                  </h2>

                  <p className="max-w-xl text-base sm:text-lg text-white/90 leading-relaxed font-normal">
                    {slide.text}
                  </p>

                  <div className="flex flex-wrap gap-3.5 mt-7">
                    <Link
                      href={slide.cta.url}
                      className="inline-flex items-center gap-2 px-6 py-3.5 rounded-[0.7rem] bg-gold text-[#0A2540] font-semibold text-sm hover:bg-[#d8bc77] hover:-translate-y-0.5 transition-all shadow-md"
                    >
                      <span>{slide.cta.label}</span>
                      <ArrowRight className="h-4 w-4" />
                    </Link>

                    <Link
                      href={slide.cta2.url}
                      className="inline-flex items-center px-6 py-3.5 rounded-[0.7rem] border border-white/40 bg-white/10 text-white font-semibold text-sm backdrop-blur-md hover:bg-white/20 hover:border-white/60 transition-all"
                    >
                      {slide.cta2.label}
                    </Link>
                  </div>
                </div>
              );
            })}
          </div>
        </div>

        {/* Hero Navigation: arrows and dots */}
        <div className="absolute right-4 sm:right-8 md:right-16 bottom-6 z-20 flex items-center gap-2.5">
          <button
            type="button"
            onClick={prevSlide}
            aria-label="Diapositiva anterior"
            className="inline-flex items-center justify-center w-11 h-11 rounded-full border border-white/35 bg-[#0A2540]/60 text-white backdrop-blur-md hover:bg-gold hover:text-ink hover:border-gold transition-colors focus-visible:outline-3 focus-visible:outline-gold"
          >
            <ChevronLeft className="h-5 w-5" />
          </button>

          <div className="flex items-center gap-2 px-1">
            {HERO_SLIDES.map((_, idx) => (
              <button
                key={idx}
                type="button"
                onClick={() => setCurrent(idx)}
                aria-label={`Ir a la diapositiva ${idx + 1}`}
                className={`h-2.5 rounded-full transition-all duration-300 ${
                  current === idx ? 'w-8 bg-gold' : 'w-2.5 bg-white/60 hover:bg-white'
                }`}
              />
            ))}
          </div>

          <button
            type="button"
            onClick={nextSlide}
            aria-label="Diapositiva siguiente"
            className="inline-flex items-center justify-center w-11 h-11 rounded-full border border-white/35 bg-[#0A2540]/60 text-white backdrop-blur-md hover:bg-gold hover:text-ink hover:border-gold transition-colors focus-visible:outline-3 focus-visible:outline-gold"
          >
            <ChevronRight className="h-5 w-5" />
          </button>
        </div>
      </div>

      {/* Hero Strip de Estadísticas exactas */}
      <div className="border-t-2 border-gold bg-[#0A1929] py-5">
        <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
          <dl className="grid grid-cols-2 lg:grid-cols-4 gap-6 lg:gap-0">
            {HERO_STATS.map((stat, idx) => (
              <div
                key={idx}
                className={`flex flex-col justify-center px-4 lg:px-8 ${
                  idx > 0 ? 'lg:border-l lg:border-white/20' : 'lg:pl-0'
                }`}
              >
                <dt className="text-2xl sm:text-3xl font-bold text-white tracking-tight leading-none mb-1">
                  {stat.value}
                </dt>
                <dd className="text-xs sm:text-sm text-white/80 leading-snug">
                  {stat.label}
                </dd>
              </div>
            ))}
          </dl>
        </div>
      </div>
    </div>
  );
}
