'use client';

import { useState, useEffect } from 'react';
import Image from 'next/image';
import { Target, Eye, Star, ChevronDown } from 'lucide-react';

export default function QuienesSomosPage() {
  const [typedText, setTypedText] = useState('');
  const fullText = '¿Quiénes Somos?';
  const [activeCard, setActiveCard] = useState<string | null>(null);

  // Typing effect exacto de quienes-somos.js
  useEffect(() => {
    let index = 0;
    const interval = setInterval(() => {
      if (index <= fullText.length) {
        setTypedText(fullText.slice(0, index));
        index++;
      } else {
        clearInterval(interval);
      }
    }, 80);
    return () => clearInterval(interval);
  }, []);

  const toggleCard = (name: string) => {
    setActiveCard((prev) => (prev === name ? null : name));
  };

  const valores = [
    'Puntualidad',
    'Confianza',
    'Calidad',
    'Vocación',
    'Superación',
    'Trabajo en equipo',
    'Empatía',
  ];

  const equipo = [
    {
      id: 'marco',
      nombre: 'MARCO ALVAREZ',
      cargo: 'GERENTE GENERAL',
      foto: '/images/somos/marco2.jpeg',
      facebook: 'https://www.facebook.com/share/174BEdCReB/',
      whatsapp: 'https://wa.me/51972021374',
      descripcion:
        'Profesional de Economía de la Universidad Nacional de Cajamarca. Experiencia en manejo de personal, trabajo en equipo. Docente en institutos de educación superior en los cursos de sociedad y economía, proyectos de investigación, taller de competencias profesionales entre otros.',
    },
    {
      id: 'monica',
      nombre: 'MÓNICA MONZÓN',
      cargo: 'COORDINADORA INSTITUCIONAL',
      foto: '/images/somos/monica.jpeg',
      facebook: 'https://web.facebook.com/monica.monzongonzales',
      whatsapp: 'https://wa.me/51972021374',
      descripcion:
        'Profesional de Enfermería por la Universidad Nacional de Cajamarca. Maestra en Ciencias con mención en Salud Ocupacional y Ambiental por la Universidad Privada Antenor Orrego - Trujillo. Actualmente labora en el Hospital Regional Docente de Cajamarca en la unidad de cuidados intensivos (UCI).',
    },
    {
      id: 'karen',
      nombre: 'KAREN TÁVARA',
      cargo: 'ASESORA LEGAL',
      foto: '/images/somos/Persona4.jpeg',
      facebook: 'https://web.facebook.com/karen.tavara1',
      whatsapp: 'https://wa.me/51972021374',
      descripcion:
        'Profesional en Derecho por la Universidad Privada Antonio Guillermo Urrelo. Con sólida experiencia en el ejercicio legal y en la docencia. Ha desempeñado funciones como abogada en diversas instituciones públicas.',
    },
    {
      id: 'milagros',
      nombre: 'MILAGROS VILLEGAS',
      cargo: 'SECRETARIA',
      foto: '/images/somos/milagros.jpeg',
      facebook: 'https://web.facebook.com/milagros.villegas.180',
      whatsapp: 'https://wa.me/51972021374',
      descripcion:
        'Profesional de enfermería técnica, con experiencia en ventas y trato al cliente entre otros.',
    },
  ];

  return (
    <div className="pt-20">
      {/* 1. Header con Typing Effect y Sticker Animado exacto de Blade */}
      <section className="relative overflow-hidden py-16 sm:py-24 bg-gradient-to-br from-[#060f1a] via-[#0A2540] to-[#0d2d4a] text-white">
        <div className="absolute inset-0 bg-[linear-gradient(rgba(201,169,97,0.04)_1px,transparent_1px),linear-gradient(90deg,rgba(201,169,97,0.04)_1px,transparent_1px)] [background-size:60px_60px] pointer-events-none"></div>

        <div className="relative z-10 mx-auto max-w-6xl px-4 sm:px-6 lg:px-8 flex flex-col md:flex-row items-center justify-between gap-8">
          <div className="max-w-2xl text-center md:text-left">
            <span className="inline-block rounded-full bg-gold/15 border border-gold/40 px-4 py-1.5 text-xs font-semibold tracking-[3px] text-gold uppercase mb-4">
              HORUS GROUP SRL
            </span>

            <h1 className="text-4xl sm:text-6xl font-extrabold tracking-tight text-white mb-4 leading-tight">
              {typedText}
              <span className="animate-blink text-gold">|</span>
            </h1>

            <h2 className="text-lg sm:text-xl text-stone-300 font-light max-w-lg leading-relaxed">
              Descubre nuestra misión, visión y valores.
            </h2>
          </div>

          <div className="shrink-0 flex items-center justify-center">
            <Image
              src="/images/index/stiker/stiker.png"
              alt="Decoración Horus"
              width={160}
              height={115}
              className="animate-mover-horizontal drop-shadow-[0_4px_12px_rgba(201,169,97,0.4)]"
              priority
            />
          </div>
        </div>
      </section>

      {/* 2. Misión, Visión y Valores en fondo #f8f9fa y tarjetas blancas exactas de Blade */}
      <section className="py-20 sm:py-24 bg-[#f8f9fa]">
        <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
          <div className="text-center max-w-2xl mx-auto mb-16">
            <span className="inline-block rounded-full bg-[#0A2540]/8 border border-[#0A2540]/15 px-4 py-1.5 text-xs font-bold tracking-widest text-[#0A2540] uppercase mb-3">
              Nuestra Identidad
            </span>
            <h2 className="text-3xl sm:text-4xl md:text-5xl font-extrabold text-[#0A2540] tracking-tight">
              Misión, Visión y Valores
            </h2>
            <p className="mt-4 text-slate-600 text-sm sm:text-base leading-relaxed">
              Los pilares que guían cada decisión y acción en Horus Group SRL.
            </p>
          </div>

          <div className="grid gap-8 md:grid-cols-3 max-w-6xl mx-auto">
            {/* Misión Card */}
            <article className="card-hover-border rounded-[20px] bg-white border border-[#0A2540]/6 p-8 shadow-[0_8px_32px_rgba(10,37,64,0.07)] hover:-translate-y-2 hover:shadow-[0_20px_50px_rgba(10,37,64,0.12)] transition-all duration-400 flex flex-col justify-between group">
              <div>
                <div className="flex h-16 w-16 items-center justify-center rounded-2xl bg-gradient-to-br from-[#0A2540] to-[#1E4976] text-gold mb-6 group-hover:scale-110 group-hover:bg-gradient-to-br group-hover:from-gold group-hover:to-gold-dark group-hover:text-white transition-all shadow-md">
                  <Target className="h-7 w-7" />
                </div>
                <h3 className="text-xl font-extrabold text-[#0A2540] uppercase tracking-wide mb-4">
                  Misión
                </h3>
                <p className="text-sm leading-relaxed text-[#4A5568] mb-6">
                  &ldquo;Somos una empresa Cajamarquina que busca el desarrollo y crecimiento de la comunidad a través de los diferentes servicios de tecnología y capacitaciones que brindamos, con calidad y vocación para que nuestros clientes se sientan satisfechos y sigan confiando en nosotros.&rdquo;
                </p>
              </div>

              <div className="relative h-48 w-full rounded-xl overflow-hidden bg-gradient-to-br from-[#eef2f8] to-[#dde6f0] p-3 flex items-center justify-center">
                <Image
                  src="/images/somos/mision1.png"
                  alt="Misión Horus Group"
                  fill
                  sizes="400px"
                  className="object-contain p-2 group-hover:scale-105 transition-transform duration-400 drop-shadow-[0_4px_12px_rgba(10,37,64,0.12)]"
                />
              </div>
            </article>

            {/* Visión Card */}
            <article className="card-hover-border rounded-[20px] bg-white border border-[#0A2540]/6 p-8 shadow-[0_8px_32px_rgba(10,37,64,0.07)] hover:-translate-y-2 hover:shadow-[0_20px_50px_rgba(10,37,64,0.12)] transition-all duration-400 flex flex-col justify-between group">
              <div>
                <div className="flex h-16 w-16 items-center justify-center rounded-2xl bg-gradient-to-br from-[#0A2540] to-[#1E4976] text-gold mb-6 group-hover:scale-110 group-hover:bg-gradient-to-br group-hover:from-gold group-hover:to-gold-dark group-hover:text-white transition-all shadow-md">
                  <Eye className="h-7 w-7" />
                </div>
                <h3 className="text-xl font-extrabold text-[#0A2540] uppercase tracking-wide mb-4">
                  Visión
                </h3>
                <p className="text-sm leading-relaxed text-[#4A5568] mb-6">
                  Lograr ser una empresa reconocida en la Región de Cajamarca, posicionándonos como líderes en el desarrollo de competencias tecnológicas y capacitaciones de alto nivel, contando con un equipo de profesionales especializados y adaptándonos a las necesidades de la población.
                </p>
              </div>

              <div className="relative h-48 w-full rounded-xl overflow-hidden bg-gradient-to-br from-[#eef2f8] to-[#dde6f0] p-3 flex items-center justify-center">
                <Image
                  src="/images/somos/visión amarillo.png"
                  alt="Visión Horus Group"
                  fill
                  sizes="400px"
                  className="object-contain p-2 group-hover:scale-105 transition-transform duration-400 drop-shadow-[0_4px_12px_rgba(10,37,64,0.12)]"
                />
              </div>
            </article>

            {/* Valores Card */}
            <article className="card-hover-border rounded-[20px] bg-white border border-[#0A2540]/6 p-8 shadow-[0_8px_32px_rgba(10,37,64,0.07)] hover:-translate-y-2 hover:shadow-[0_20px_50px_rgba(10,37,64,0.12)] transition-all duration-400 flex flex-col justify-between group">
              <div>
                <div className="flex h-16 w-16 items-center justify-center rounded-2xl bg-gradient-to-br from-[#0A2540] to-[#1E4976] text-gold mb-6 group-hover:scale-110 group-hover:bg-gradient-to-br group-hover:from-gold group-hover:to-gold-dark group-hover:text-white transition-all shadow-md">
                  <Star className="h-7 w-7" />
                </div>
                <h3 className="text-xl font-extrabold text-[#0A2540] uppercase tracking-wide mb-3">
                  Valores
                </h3>
                <p className="text-xs text-slate-500 mb-4">
                  En Horus Group SRL nos caracterizamos por los siguientes valores institucionales:
                </p>

                <ul className="grid grid-cols-2 gap-2 text-xs font-semibold text-slate-700 mb-6">
                  {valores.map((val, idx) => (
                    <li key={idx} className="flex items-center gap-2">
                      <span className="h-2 w-2 rounded-full bg-gold shrink-0"></span>
                      <span>{val}</span>
                    </li>
                  ))}
                </ul>
              </div>

              <div className="relative h-48 w-full rounded-xl overflow-hidden bg-gradient-to-br from-[#eef2f8] to-[#dde6f0] p-3 flex items-center justify-center">
                <Image
                  src="/images/somos/valores4.png"
                  alt="Valores Horus Group"
                  fill
                  sizes="400px"
                  className="object-contain p-2 group-hover:scale-105 transition-transform duration-400 drop-shadow-[0_4px_12px_rgba(10,37,64,0.12)]"
                />
              </div>
            </article>
          </div>
        </div>
      </section>

      {/* 3. Sección Nuestro Equipo en fondo degradado claro y tarjetas blancas exactas de Blade */}
      <section className="py-20 sm:py-24 bg-gradient-to-b from-white to-[#f8f9fa] border-t border-stone-200">
        <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
          <div className="text-center max-w-2xl mx-auto mb-16">
            <span className="inline-block rounded-full bg-[#0A2540]/7 border border-[#0A2540]/12 px-4 py-1.5 text-xs font-bold tracking-widest text-[#0A2540] uppercase mb-3">
              Nuestro Equipo
            </span>
            <h2 className="text-3xl sm:text-4xl md:text-5xl font-extrabold text-[#0A2540] tracking-tight">
              Liderazgo y Experiencia
            </h2>
            <p className="mt-4 text-slate-600 text-sm sm:text-base leading-relaxed">
              Conoce a los profesionales que hacen posible el crecimiento de Horus Group SRL.
            </p>
          </div>

          <div className="grid gap-8 sm:grid-cols-2 max-w-4xl mx-auto">
            {equipo.map((persona) => {
              const isActiva = activeCard === persona.id;

              return (
                <div
                  key={persona.id}
                  onClick={() => toggleCard(persona.id)}
                  className={`card-hover-border cursor-pointer rounded-[20px] bg-white p-8 shadow-[0_8px_30px_rgba(10,37,64,0.07)] hover:-translate-y-2 hover:shadow-[0_24px_56px_rgba(10,37,64,0.13)] border border-[#0A2540]/5 transition-all duration-400 text-center relative overflow-hidden group ${
                    isActiva ? 'ring-2 ring-gold/40 border-gold/50 shadow-md' : ''
                  }`}
                >
                  {/* Foto circular con borde dorado de 4px */}
                  <div className="relative h-32 w-32 mx-auto rounded-full overflow-hidden border-4 border-gold shadow-[0_8px_24px_rgba(201,169,97,0.25)] mb-5 group-hover:scale-105 transition-transform duration-400">
                    <Image
                      src={persona.foto}
                      alt={persona.nombre}
                      fill
                      sizes="130px"
                      className="object-cover"
                    />
                  </div>

                  <h3 className="text-lg font-bold text-[#0A2540] tracking-wide mb-1">
                    {persona.nombre}
                  </h3>

                  <span className="block text-xs font-semibold text-gold tracking-widest uppercase mb-4">
                    {persona.cargo}
                  </span>

                  {/* Redes Sociales circulares */}
                  <div className="flex justify-center items-center gap-3 mb-4" onClick={(e) => e.stopPropagation()}>
                    <a
                      href={persona.facebook}
                      target="_blank"
                      rel="noopener noreferrer"
                      className="flex h-9 w-9 items-center justify-center rounded-full bg-stone-100 border border-stone-200 text-slate-600 hover:bg-gold hover:text-white hover:-translate-y-1 transition-all"
                      aria-label={`Facebook de ${persona.nombre}`}
                    >
                      <svg width="16" height="16" fill="currentColor" viewBox="0 0 24 24"><path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/></svg>
                    </a>

                    <a
                      href={persona.whatsapp}
                      target="_blank"
                      rel="noopener noreferrer"
                      className="flex h-9 w-9 items-center justify-center rounded-full bg-stone-100 border border-stone-200 text-slate-600 hover:bg-gold hover:text-white hover:-translate-y-1 transition-all"
                      aria-label={`WhatsApp de ${persona.nombre}`}
                    >
                      <svg width="16" height="16" fill="currentColor" viewBox="0 0 24 24"><path d="M.057 24l1.687-6.163c-1.041-1.804-1.588-3.849-1.587-5.946.003-6.556 5.338-11.891 11.893-11.891 3.181.001 6.167 1.24 8.413 3.488 2.245 2.248 3.481 5.236 3.48 8.414-.003 6.557-5.338 11.892-11.893 11.892-1.99-.001-3.951-.5-5.688-1.448l-6.305 1.654zm6.597-3.807c1.676.995 3.276 1.591 5.392 1.592 5.448 0 9.886-4.434 9.889-9.885.002-5.462-4.415-9.89-9.881-9.892-5.452 0-9.887 4.434-9.889 9.884-.001 2.225.651 3.891 1.746 5.634l-.999 3.648 3.742-.981zm11.387-5.464c-.074-.124-.272-.198-.57-.347-.297-.149-1.758-.868-2.031-.967-.272-.099-.47-.149-.669.149-.198.297-.768.967-.941 1.165-.173.198-.347.223-.644.074-.297-.149-1.255-.462-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.297-.347.446-.521.151-.172.2-.296.3-.495.099-.198.05-.372-.025-.521-.075-.148-.669-1.611-.916-2.206-.242-.579-.487-.501-.669-.51l-.57-.01c-.198 0-.52.074-.792.372s-1.04 1.016-1.04 2.479 1.065 2.876 1.213 3.074c.149.198 2.095 3.2 5.076 4.487.709.306 1.263.489 1.694.626.712.226 1.36.194 1.872.118.571-.085 1.758-.719 2.006-1.413.248-.695.248-1.29.173-1.414z"/></svg>
                    </a>
                  </div>

                  {/* Toggle button */}
                  <div className="inline-flex items-center gap-1.5 text-xs font-semibold text-slate-500 group-hover:text-gold transition-colors mb-2">
                    <span>{isActiva ? 'Ocultar información' : 'Conoce más'}</span>
                    <ChevronDown className={`h-4 w-4 transition-transform duration-300 ${isActiva ? 'rotate-180 text-gold' : ''}`} />
                  </div>

                  {/* Descripción desplegable */}
                  <div
                    className={`overflow-hidden transition-all duration-400 text-xs sm:text-sm text-slate-600 leading-relaxed ${
                      isActiva ? 'max-h-60 mt-3 pt-3 border-t border-stone-100 opacity-100' : 'max-h-0 opacity-0'
                    }`}
                  >
                    <p>{persona.descripcion}</p>
                  </div>
                </div>
              );
            })}
          </div>
        </div>
      </section>
    </div>
  );
}
