'use client';

import { useState } from 'react';
import Link from 'next/link';
import {
  Award,
  BookOpen,
  Clock,
  GraduationCap,
  Laptop,
  Home,
  Check,
  ArrowRight,
} from 'lucide-react';
import { CURSOS_CATALOGO, MODALIDADES_INFO } from '@/data/cursos';
import { CONTACT_INFO } from '@/data/contactos';

export default function CursosPage() {
  const [modalidadFiltro, setModalidadFiltro] = useState<string>('todos');

  const cursosFiltrados =
    modalidadFiltro === 'todos'
      ? CURSOS_CATALOGO
      : CURSOS_CATALOGO.filter((c) => c.modalidad === modalidadFiltro);

  return (
    <div className="pt-20">
      {/* Intro Cursos 1:1 con cursos.blade.php */}
      <section className="relative overflow-hidden py-16 sm:py-24 bg-gradient-to-br from-[#0A2540] via-[#102d49] to-[#1a2f45] text-white text-center">
        <div className="mx-auto max-w-4xl px-4 sm:px-6 lg:px-8">
          {/* Badge */}
          <div className="inline-flex items-center gap-2.5 px-6 py-2.5 rounded-full border-2 border-gold bg-gold/20 mb-8">
            <Award className="h-5 w-5 text-gold" />
            <span className="text-sm font-semibold tracking-wider text-gold uppercase">
              CERTIFICACIÓN OFICIAL
            </span>
          </div>

          <h2 className="text-3xl sm:text-5xl font-bold tracking-tight text-white leading-tight mb-6">
            CURSOS CON CERTIFICADOS FÍSICOS Y DIGITALES
          </h2>

          <p className="text-base sm:text-lg text-white/90 leading-relaxed max-w-3xl mx-auto mb-10 text-justify sm:text-center">
            Te ofrecemos diferentes tipos de cursos según las necesidades del mercado para que puedas seguir
            mejorando tus conocimientos teórico-práctico donde puedas seguir desarrollándote personal y
            profesionalmente; para lo cual te damos las facilidades de poder realizar estos cursos a
            través de las siguientes modalidades según sea el caso.
          </p>

          {/* 3 Stats */}
          <div className="grid grid-cols-1 sm:grid-cols-3 gap-4 sm:gap-6 max-w-3xl mx-auto">
            <div className="flex items-center gap-4 p-4 sm:p-5 rounded-xl border-2 border-gold/30 bg-white/10 hover:border-gold hover:-translate-y-1 transition-all">
              <BookOpen className="h-8 w-8 text-gold shrink-0" />
              <div className="text-left">
                <strong className="block text-2xl font-bold text-white">+10</strong>
                <span className="text-xs text-white/80">Cursos Disponibles.</span>
              </div>
            </div>

            <div className="flex items-center gap-4 p-4 sm:p-5 rounded-xl border-2 border-gold/30 bg-white/10 hover:border-gold hover:-translate-y-1 transition-all">
              <Clock className="h-8 w-8 text-gold shrink-0" />
              <div className="text-left">
                <strong className="block text-2xl font-bold text-white">FLEXIBLE</strong>
                <span className="text-xs text-white/80">Horarios Adaptables.</span>
              </div>
            </div>

            <div className="flex items-center gap-4 p-4 sm:p-5 rounded-xl border-2 border-gold/30 bg-white/10 hover:border-gold hover:-translate-y-1 transition-all">
              <Award className="h-8 w-8 text-gold shrink-0" />
              <div className="text-left">
                <strong className="block text-2xl font-bold text-white">DOBLE</strong>
                <span className="text-xs text-white/80">Certificación.</span>
              </div>
            </div>
          </div>
        </div>
      </section>

      {/* Modalidades de Cursos en fondo blanco con degradado claro */}
      <section className="bg-gradient-to-b from-white to-[#f8f9fa] py-16 sm:py-24">
        <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
          <div className="text-center mb-16">
            <h2 className="relative inline-block pb-4 text-3xl sm:text-4xl md:text-5xl font-bold tracking-tight text-[#0A2540]">
              MODALIDADES DE CURSOS
              <span className="absolute bottom-0 left-1/2 -translate-x-1/2 h-1 w-32 rounded-full animate-line-shine"></span>
            </h2>
            <p className="mt-4 text-base text-slate-600">
              Elige la modalidad que mejor se adapte a tu estilo de vida.
            </p>
          </div>

          <div className="grid gap-8 md:grid-cols-3">
            {/* Presencial */}
            <article className="card-hover-border rounded-2xl bg-white border border-stone-200 p-8 shadow-[0_8px_30px_rgba(0,0,0,0.1)] hover:-translate-y-3 hover:shadow-[0_15px_50px_rgba(0,0,0,0.18)] hover:border-gold transition-all duration-400 flex flex-col justify-between relative overflow-hidden">
              <div>
                <span className="inline-block px-3 py-1 rounded-full text-xs font-bold bg-stone-100 text-[#0A2540] border border-stone-200 mb-6">
                  Tradicional
                </span>

                <div className="flex h-16 w-16 items-center justify-center rounded-full bg-gradient-to-br from-[#0A2540] to-gold shadow-md text-white mb-6">
                  <GraduationCap className="h-8 w-8" />
                </div>

                <h3 className="text-2xl font-bold text-[#0A2540] mb-4">PRESENCIAL</h3>

                <p className="text-sm sm:text-base text-slate-600 leading-relaxed mb-6">
                  Nuestras sesiones teóricas y prácticas se desarrollan íntegramente de forma presencial. El
                  objetivo es potenciar tus habilidades y destrezas mediante un aprendizaje directo y
                  personalizado.
                </p>

                <div className="space-y-2.5 border-t border-stone-100 pt-5 mb-8">
                  <div className="flex items-center gap-2 text-xs sm:text-sm text-slate-700 font-medium">
                    <Check className="h-4 w-4 text-gold shrink-0" />
                    <span>Interacción directa.</span>
                  </div>
                  <div className="flex items-center gap-2 text-xs sm:text-sm text-slate-700 font-medium">
                    <Check className="h-4 w-4 text-gold shrink-0" />
                    <span>Práctica inmediata.</span>
                  </div>
                  <div className="flex items-center gap-2 text-xs sm:text-sm text-slate-700 font-medium">
                    <Check className="h-4 w-4 text-gold shrink-0" />
                    <span>Networking presencial.</span>
                  </div>
                </div>
              </div>

              <div>
                <button
                  type="button"
                  onClick={() => {
                    setModalidadFiltro('presencial');
                    document.getElementById('catalogo-cursos')?.scrollIntoView({ behavior: 'smooth' });
                  }}
                  className="inline-flex items-center gap-2 text-sm font-bold text-[#0A2540] hover:text-gold transition-colors"
                >
                  <span>Ver cursos</span>
                  <ArrowRight className="h-4 w-4" />
                </button>
              </div>
            </article>

            {/* Semipresencial (Destacada) */}
            <article className="card-hover-border rounded-2xl bg-white border-2 border-gold p-8 shadow-[0_12px_40px_rgba(201,169,97,0.2)] hover:-translate-y-3 hover:shadow-[0_20px_60px_rgba(201,169,97,0.3)] transition-all duration-400 flex flex-col justify-between relative overflow-hidden ring-2 ring-gold/20">
              <div>
                <span className="inline-block px-3 py-1 rounded-full text-xs font-bold bg-gold text-[#0A2540] shadow-sm mb-6">
                  Más Popular
                </span>

                <div className="flex h-16 w-16 items-center justify-center rounded-full bg-gradient-to-br from-[#0A2540] to-gold shadow-md text-white mb-6">
                  <Home className="h-8 w-8" />
                </div>

                <h3 className="text-2xl font-bold text-[#0A2540] mb-4">SEMIPRESENCIAL</h3>

                <p className="text-sm sm:text-base text-slate-600 leading-relaxed mb-6">
                  Combina la flexibilidad digital con la experiencia práctica. Accede a tus clases teóricas
                  mediante sesiones online en vivo con interacción directa; además, tendrás acceso a las
                  grabaciones para repasar a tu ritmo. Las sesiones prácticas son 100% presenciales y nos
                  adaptamos a tu agenda con horarios semanales o mensuales.
                </p>

                <div className="space-y-2.5 border-t border-stone-100 pt-5 mb-8">
                  <div className="flex items-center gap-2 text-xs sm:text-sm text-slate-700 font-medium">
                    <Check className="h-4 w-4 text-gold shrink-0" />
                    <span>Lo mejor de ambos mundos.</span>
                  </div>
                  <div className="flex items-center gap-2 text-xs sm:text-sm text-slate-700 font-medium">
                    <Check className="h-4 w-4 text-gold shrink-0" />
                    <span>Clases grabadas.</span>
                  </div>
                  <div className="flex items-center gap-2 text-xs sm:text-sm text-slate-700 font-medium">
                    <Check className="h-4 w-4 text-gold shrink-0" />
                    <span>Horarios flexibles.</span>
                  </div>
                </div>
              </div>

              <div>
                <button
                  type="button"
                  onClick={() => {
                    setModalidadFiltro('semipresencial');
                    document.getElementById('catalogo-cursos')?.scrollIntoView({ behavior: 'smooth' });
                  }}
                  className="inline-flex items-center gap-2 text-sm font-bold text-[#0A2540] hover:text-gold transition-colors"
                >
                  <span>Ver cursos</span>
                  <ArrowRight className="h-4 w-4" />
                </button>
              </div>
            </article>

            {/* Virtual */}
            <article className="card-hover-border rounded-2xl bg-white border border-stone-200 p-8 shadow-[0_8px_30px_rgba(0,0,0,0.1)] hover:-translate-y-3 hover:shadow-[0_15px_50px_rgba(0,0,0,0.18)] hover:border-gold transition-all duration-400 flex flex-col justify-between relative overflow-hidden">
              <div>
                <span className="inline-block px-3 py-1 rounded-full text-xs font-bold bg-stone-100 text-[#0A2540] border border-stone-200 mb-6">
                  100% Online
                </span>

                <div className="flex h-16 w-16 items-center justify-center rounded-full bg-gradient-to-br from-[#0A2540] to-gold shadow-md text-white mb-6">
                  <Laptop className="h-8 w-8" />
                </div>

                <h3 className="text-2xl font-bold text-[#0A2540] mb-4">VIRTUAL</h3>

                <p className="text-sm sm:text-base text-slate-600 leading-relaxed mb-6">
                  Lleva tus clases desde la comodidad de tu hogar, donde puedas seguir aprendiendo y mejorando tus
                  conocimientos teóricos y poder repasar después los puntos que necesites debido a que
                  nuestras clases son grabadas.
                </p>

                <div className="space-y-2.5 border-t border-stone-100 pt-5 mb-8">
                  <div className="flex items-center gap-2 text-xs sm:text-sm text-slate-700 font-medium">
                    <Check className="h-4 w-4 text-gold shrink-0" />
                    <span>Desde cualquier lugar.</span>
                  </div>
                  <div className="flex items-center gap-2 text-xs sm:text-sm text-slate-700 font-medium">
                    <Check className="h-4 w-4 text-gold shrink-0" />
                    <span>Clases grabadas.</span>
                  </div>
                  <div className="flex items-center gap-2 text-xs sm:text-sm text-slate-700 font-medium">
                    <Check className="h-4 w-4 text-gold shrink-0" />
                    <span>Aprende a tu ritmo.</span>
                  </div>
                </div>
              </div>

              <div>
                <button
                  type="button"
                  onClick={() => {
                    setModalidadFiltro('virtual');
                    document.getElementById('catalogo-cursos')?.scrollIntoView({ behavior: 'smooth' });
                  }}
                  className="inline-flex items-center gap-2 text-sm font-bold text-[#0A2540] hover:text-gold transition-colors"
                >
                  <span>Ver cursos</span>
                  <ArrowRight className="h-4 w-4" />
                </button>
              </div>
            </article>
          </div>
        </div>
      </section>

      {/* Catálogo de Cursos Publicados */}
      <section id="catalogo-cursos" className="bg-white py-16 sm:py-24 border-t border-stone-200 scroll-mt-6">
        <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
          <div className="text-center mb-12">
            <h2 className="text-2xl sm:text-3xl md:text-4xl font-bold text-[#0A2540] tracking-tight">
              {modalidadFiltro !== 'todos'
                ? `CURSOS: ${modalidadFiltro.toUpperCase()}`
                : 'CURSOS PUBLICADOS'}
            </h2>
            <div className="mt-3 h-1 w-20 rounded-full bg-gold mx-auto"></div>
            <p className="mt-3 text-sm text-slate-600">
              {cursosFiltrados.length} programas vigentes. Consulta información o solicita tu matrícula directa.
            </p>

            {/* Chips de filtro */}
            <div className="flex flex-wrap justify-center gap-2 mt-6">
              {[
                { id: 'todos', label: 'Todos' },
                { id: 'presencial', label: 'Presencial' },
                { id: 'semipresencial', label: 'Semipresencial' },
                { id: 'virtual', label: 'Virtual' },
              ].map((f) => (
                <button
                  key={f.id}
                  type="button"
                  onClick={() => setModalidadFiltro(f.id)}
                  className={`px-4 py-1.5 rounded-full text-xs font-semibold transition-all ${
                    modalidadFiltro === f.id
                      ? 'bg-[#0A2540] text-white shadow-md'
                      : 'bg-stone-100 text-slate-700 hover:bg-stone-200'
                  }`}
                >
                  {f.label}
                </button>
              ))}
            </div>
          </div>

          <div className="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
            {cursosFiltrados.map((curso) => (
              <article
                key={curso.id}
                className="rounded-2xl border border-stone-200 bg-white p-6 shadow-sm hover:shadow-md transition-all flex flex-col justify-between"
              >
                <div>
                  <span className="inline-block px-3 py-1 rounded-full text-xs font-bold bg-gold/15 text-slate-800 border border-gold/30 mb-3 uppercase">
                    {curso.modalidad}
                  </span>
                  <h3 className="text-lg font-bold text-[#0A2540] mb-2 leading-snug">
                    {curso.titulo}
                  </h3>
                  <p className="text-xs sm:text-sm text-slate-600 leading-relaxed mb-4">
                    {curso.resumen}
                  </p>
                  <div className="space-y-1.5 text-xs text-slate-600 border-t border-stone-100 pt-3">
                    <p>
                      <strong>Horario:</strong> {curso.horario}
                    </p>
                    <p>
                      <strong>Duración:</strong> {curso.duracion}
                    </p>
                    <p>
                      <strong>Certificación:</strong> {curso.certificacion}
                    </p>
                  </div>
                </div>

                <div className="mt-6 pt-4 border-t border-stone-100 flex items-center justify-between gap-3">
                  <Link
                    href="/contactos"
                    className="text-xs font-semibold text-slate-700 underline hover:text-[#0A2540]"
                  >
                    Consultar curso
                  </Link>

                  <a
                    href={`https://wa.me/${CONTACT_INFO.phoneClean}?text=${encodeURIComponent(`Hola, deseo solicitar matrícula para el curso: ${curso.titulo}.`)}`}
                    target="_blank"
                    rel="noopener noreferrer"
                    className="inline-flex items-center justify-center px-4 py-2 rounded-xl bg-[#0A2540] hover:bg-gold hover:text-[#0A2540] text-white text-xs font-semibold transition-colors shadow-sm"
                  >
                    Solicitar matrícula
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
