'use client';

import { useEffect, useState } from 'react';
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
  CalendarCheck,
  CalendarDays,
  Hourglass,
  MessageCircle,
  Calendar,
  X,
} from 'lucide-react';
import type { CursoPublico } from '@/lib/horus-api';
import { CONTACT_INFO } from '@/data/contactos';

export type EstadoCatalogo = 'cargando' | 'ok' | 'apagado' | 'no_disponible';

interface CursosViewProps {
  cursos: CursoPublico[];
  estado: EstadoCatalogo;
}

const MENSAJES_ESTADO: Record<Exclude<EstadoCatalogo, 'ok'>, string> = {
  cargando: 'Cargando el listado de cursos…',
  apagado: 'El listado de cursos aún no está disponible en la web.',
  no_disponible: 'No pudimos cargar el listado de cursos en este momento. Inténtalo más tarde.',
};

const MODALIDAD_META: Record<
  CursoPublico['modalidad'],
  { etiqueta: string; Icono: typeof GraduationCap }
> = {
  presencial: { etiqueta: 'Presencial', Icono: GraduationCap },
  semipresencial: { etiqueta: 'Semipresencial', Icono: Home },
  virtual: { etiqueta: 'Virtual', Icono: Laptop },
  hibrido: { etiqueta: 'Híbrido', Icono: BookOpen },
};

function formatearFechaCorta(iso: string): string | null {
  if (!iso) return null;
  const fecha = new Date(`${iso.slice(0, 10)}T00:00:00`);
  if (Number.isNaN(fecha.getTime())) return null;
  return new Intl.DateTimeFormat('es-PE', {
    day: '2-digit',
    month: 'short',
    year: 'numeric',
  }).format(fecha);
}

export default function CursosView({ cursos, estado }: CursosViewProps) {
  const [modalidadFiltro, setModalidadFiltro] = useState<string>('todos');
  const [cursoDetalle, setCursoDetalle] = useState<CursoPublico | null>(null);

  useEffect(() => {
    if (!cursoDetalle) return;
    const alPresionarTecla = (e: KeyboardEvent) => {
      if (e.key === 'Escape') setCursoDetalle(null);
    };
    window.addEventListener('keydown', alPresionarTecla);
    return () => window.removeEventListener('keydown', alPresionarTecla);
  }, [cursoDetalle]);

  const cursosFiltrados =
    modalidadFiltro === 'todos' ? cursos : cursos.filter((c) => c.modalidad === modalidadFiltro);

  const consultarCursoEnChat = (curso: CursoPublico) => {
    setCursoDetalle(null);
    window.dispatchEvent(
      new CustomEvent('horus-consultar-curso', {
        detail: {
          titulo: curso.titulo,
          modalidad: (MODALIDAD_META[curso.modalidad] ?? MODALIDAD_META.presencial).etiqueta,
          horario: curso.horario ?? null,
          duracion: curso.duracion ?? null,
          fecha_inicio: curso.fecha_inicio ? formatearFechaCorta(curso.fecha_inicio) : null,
          certificacion: curso.certificacion ?? null,
        },
      })
    );
  };

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

          <div className="grid gap-6 lg:gap-8 md:grid-cols-3 items-stretch">
            {/* Presencial */}
            <article className="group rounded-2xl bg-white border border-stone-200/90 p-7 sm:p-8 shadow-[0_6px_24px_rgba(10,37,64,0.06)] hover:-translate-y-1 hover:shadow-[0_14px_34px_rgba(10,37,64,0.1)] hover:border-gold/60 transition-all duration-300 flex flex-col">
              <div className="flex items-center justify-between gap-3 mb-6">
                <div className="flex h-12 w-12 items-center justify-center rounded-xl bg-[#0A2540]/6 text-[#0A2540] border border-[#0A2540]/10 group-hover:bg-[#0A2540] group-hover:text-gold transition-colors">
                  <GraduationCap className="h-6 w-6" />
                </div>
                <span className="inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold bg-stone-100 text-[#0A2540] border border-stone-200/80">
                  Tradicional
                </span>
              </div>

              <h3 className="text-xl font-bold tracking-tight text-[#0A2540] mb-3">PRESENCIAL</h3>

              <p className="text-sm text-slate-600 leading-relaxed mb-6">
                Sesiones teóricas y prácticas íntegramente presenciales para potenciar tus habilidades con aprendizaje directo y personalizado.
              </p>

              <ul className="space-y-2.5 border-t border-stone-100 pt-5 mb-7 text-sm text-slate-700 font-medium">
                <li className="flex items-center gap-2.5">
                  <Check className="h-4 w-4 text-gold-text shrink-0" />
                  <span>Interacción directa</span>
                </li>
                <li className="flex items-center gap-2.5">
                  <Check className="h-4 w-4 text-gold-text shrink-0" />
                  <span>Práctica inmediata</span>
                </li>
                <li className="flex items-center gap-2.5">
                  <Check className="h-4 w-4 text-gold-text shrink-0" />
                  <span>Networking presencial</span>
                </li>
              </ul>

              <div className="mt-auto pt-2">
                <button
                  type="button"
                  onClick={() => {
                    setModalidadFiltro('presencial');
                    document.getElementById('catalogo-cursos')?.scrollIntoView({ behavior: 'smooth' });
                  }}
                  className="inline-flex w-full items-center justify-between rounded-xl border border-stone-200 bg-stone-50/70 px-4 py-2.5 text-sm font-semibold text-[#0A2540] hover:border-[#0A2540] hover:bg-[#0A2540] hover:text-white focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#0A2540] focus-visible:ring-offset-2 transition-colors"
                >
                  <span>Ver cursos presenciales</span>
                  <ArrowRight className="h-4 w-4" />
                </button>
              </div>
            </article>

            {/* Semipresencial (Destacada) */}
            <article className="group relative rounded-2xl bg-gradient-to-b from-gold/[0.06] via-white to-white border border-gold/70 p-7 sm:p-8 shadow-[0_10px_30px_rgba(201,169,97,0.16)] hover:-translate-y-1 hover:shadow-[0_16px_40px_rgba(201,169,97,0.22)] transition-all duration-300 flex flex-col overflow-hidden">
              <div aria-hidden="true" className="absolute inset-x-0 top-0 h-1 bg-gradient-to-r from-[#0A2540] via-gold to-[#0A2540]" />

              <div className="flex items-center justify-between gap-3 mb-6">
                <div className="flex h-12 w-12 items-center justify-center rounded-xl bg-[#0A2540] text-gold shadow-sm">
                  <Home className="h-6 w-6" />
                </div>
                <span className="inline-flex items-center px-3 py-1 rounded-full text-xs font-bold bg-gold text-[#0A2540]">
                  Más Popular
                </span>
              </div>

              <h3 className="text-xl font-bold tracking-tight text-[#0A2540] mb-3">SEMIPRESENCIAL</h3>

              <p className="text-sm text-slate-600 leading-relaxed mb-6">
                Combina clases teóricas online en vivo y grabadas con sesiones prácticas 100% presenciales en horarios semanales o mensuales.
              </p>

              <ul className="space-y-2.5 border-t border-gold/20 pt-5 mb-7 text-sm text-slate-700 font-medium">
                <li className="flex items-center gap-2.5">
                  <Check className="h-4 w-4 text-gold-text shrink-0" />
                  <span>Lo mejor de ambos mundos</span>
                </li>
                <li className="flex items-center gap-2.5">
                  <Check className="h-4 w-4 text-gold-text shrink-0" />
                  <span>Clases grabadas de repaso</span>
                </li>
                <li className="flex items-center gap-2.5">
                  <Check className="h-4 w-4 text-gold-text shrink-0" />
                  <span>Horarios flexibles</span>
                </li>
              </ul>

              <div className="mt-auto pt-2">
                <button
                  type="button"
                  onClick={() => {
                    setModalidadFiltro('semipresencial');
                    document.getElementById('catalogo-cursos')?.scrollIntoView({ behavior: 'smooth' });
                  }}
                  className="inline-flex w-full items-center justify-between rounded-xl bg-[#0A2540] px-4 py-2.5 text-sm font-semibold text-white hover:bg-gold hover:text-[#0A2540] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#0A2540] focus-visible:ring-offset-2 transition-colors"
                >
                  <span>Ver cursos semipresenciales</span>
                  <ArrowRight className="h-4 w-4" />
                </button>
              </div>
            </article>

            {/* Virtual */}
            <article className="group rounded-2xl bg-white border border-stone-200/90 p-7 sm:p-8 shadow-[0_6px_24px_rgba(10,37,64,0.06)] hover:-translate-y-1 hover:shadow-[0_14px_34px_rgba(10,37,64,0.1)] hover:border-gold/60 transition-all duration-300 flex flex-col">
              <div className="flex items-center justify-between gap-3 mb-6">
                <div className="flex h-12 w-12 items-center justify-center rounded-xl bg-[#0A2540]/6 text-[#0A2540] border border-[#0A2540]/10 group-hover:bg-[#0A2540] group-hover:text-gold transition-colors">
                  <Laptop className="h-6 w-6" />
                </div>
                <span className="inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold bg-stone-100 text-[#0A2540] border border-stone-200/80">
                  100% Online
                </span>
              </div>

              <h3 className="text-xl font-bold tracking-tight text-[#0A2540] mb-3">VIRTUAL</h3>

              <p className="text-sm text-slate-600 leading-relaxed mb-6">
                Estudia desde casa con clases teóricas en línea y acceso a grabaciones para repasar cada módulo a tu propio ritmo.
              </p>

              <ul className="space-y-2.5 border-t border-stone-100 pt-5 mb-7 text-sm text-slate-700 font-medium">
                <li className="flex items-center gap-2.5">
                  <Check className="h-4 w-4 text-gold-text shrink-0" />
                  <span>Desde cualquier lugar</span>
                </li>
                <li className="flex items-center gap-2.5">
                  <Check className="h-4 w-4 text-gold-text shrink-0" />
                  <span>Clases grabadas</span>
                </li>
                <li className="flex items-center gap-2.5">
                  <Check className="h-4 w-4 text-gold-text shrink-0" />
                  <span>Aprende a tu ritmo</span>
                </li>
              </ul>

              <div className="mt-auto pt-2">
                <button
                  type="button"
                  onClick={() => {
                    setModalidadFiltro('virtual');
                    document.getElementById('catalogo-cursos')?.scrollIntoView({ behavior: 'smooth' });
                  }}
                  className="inline-flex w-full items-center justify-between rounded-xl border border-stone-200 bg-stone-50/70 px-4 py-2.5 text-sm font-semibold text-[#0A2540] hover:border-[#0A2540] hover:bg-[#0A2540] hover:text-white focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#0A2540] focus-visible:ring-offset-2 transition-colors"
                >
                  <span>Ver cursos virtuales</span>
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
              {estado === 'ok'
                ? `${cursosFiltrados.length} ${cursosFiltrados.length === 1 ? 'curso disponible' : 'cursos disponibles'}. Consulta información o solicita tu matrícula directa.`
                : 'Consulta información o solicita tu matrícula directa.'}
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
                  className={`px-4 py-1.5 rounded-full text-xs font-semibold transition-all focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#0A2540] focus-visible:ring-offset-2 ${
                    modalidadFiltro === f.id
                      ? 'bg-[#0A2540] text-white shadow-sm'
                      : 'bg-stone-100 text-slate-700 hover:bg-stone-200'
                  }`}
                >
                  {f.label}
                </button>
              ))}
            </div>
          </div>

          {estado !== 'ok' && (
            <div
              role="status"
              className="mx-auto max-w-xl rounded-2xl border border-stone-200 bg-stone-50 px-6 py-8 text-center text-sm text-slate-600"
            >
              <p>{MENSAJES_ESTADO[estado]}</p>
              <Link
                href="/contactos"
                className="mt-3 inline-flex items-center gap-1.5 font-semibold text-[#0A2540] underline underline-offset-4 hover:text-gold-text"
              >
                <span>Contáctanos</span>
                <ArrowRight className="h-3.5 w-3.5" />
              </Link>
            </div>
          )}

          {estado === 'ok' && cursosFiltrados.length === 0 && (
            <div
              role="status"
              className="mx-auto max-w-xl rounded-2xl border border-stone-200 bg-stone-50 px-6 py-8 text-center text-sm text-slate-600"
            >
              <p>No hay cursos publicados vigentes para esta modalidad.</p>
              <Link
                href="/contactos"
                className="mt-3 inline-flex items-center gap-1.5 font-semibold text-[#0A2540] underline underline-offset-4 hover:text-gold-text"
              >
                <span>Consulta por otros programas</span>
                <ArrowRight className="h-3.5 w-3.5" />
              </Link>
            </div>
          )}

          {cursosFiltrados.length > 0 && (
            <div
              className={
                cursosFiltrados.length === 1
                  ? 'mx-auto max-w-md'
                  : cursosFiltrados.length === 2
                    ? 'mx-auto grid max-w-4xl gap-7 sm:grid-cols-2'
                    : 'grid gap-7 sm:grid-cols-2 lg:grid-cols-3'
              }
            >
              {cursosFiltrados.map((curso) => {
                const meta = MODALIDAD_META[curso.modalidad] ?? MODALIDAD_META.presencial;
                const IconoModalidad = meta.Icono;
                const fechaVigencia = formatearFechaCorta(curso.vigente_hasta);
                const fechaInicio = curso.fecha_inicio ? formatearFechaCorta(curso.fecha_inicio) : null;
                const tieneDetalles = Boolean(curso.temario || curso.inscripcion);
                const tieneMeta = Boolean(fechaInicio || curso.duracion || curso.horario || curso.certificacion);

                return (
                  <article
                    key={`${curso.titulo}-${curso.vigente_desde}`}
                    className="group rounded-2xl border border-stone-200/90 bg-white shadow-[0_8px_26px_rgba(10,37,64,0.06)] hover:shadow-[0_16px_38px_rgba(10,37,64,0.11)] hover:border-gold/60 transition-all duration-300 overflow-hidden flex flex-col"
                  >
                    {/* Imagen referencial o respaldo visual */}
                    <div className="relative aspect-[16/10] w-full overflow-hidden bg-gradient-to-br from-[#0A2540] via-[#0F3154] to-[#174066]">
                      {curso.imagen_url ? (
                        <>
                          {/* eslint-disable-next-line @next/next/no-img-element */}
                          <img
                            src={curso.imagen_url}
                            alt={curso.titulo}
                            loading="lazy"
                            className="h-full w-full object-cover transition-transform duration-500 group-hover:scale-[1.03]"
                          />
                          <div
                            aria-hidden="true"
                            className="absolute inset-0 bg-gradient-to-t from-[#0A2540]/60 via-[#0A2540]/10 to-transparent"
                          />
                        </>
                      ) : (
                        <div className="flex h-full w-full flex-col items-center justify-center gap-2 text-white/75">
                          <div className="flex h-14 w-14 items-center justify-center rounded-2xl border border-gold/35 bg-white/10 text-gold shadow-inner">
                            <IconoModalidad className="h-7 w-7" />
                          </div>
                          <span className="text-[11px] font-medium tracking-wide text-white/65 uppercase">
                            Horus Educación
                          </span>
                        </div>
                      )}

                      <div className="absolute inset-x-4 top-3.5 flex items-center justify-between gap-2">
                        <span className="inline-flex items-center gap-1.5 rounded-full bg-[#0A2540]/85 backdrop-blur-sm border border-gold/40 px-3 py-1 text-xs font-semibold text-gold-light shadow-sm">
                          <IconoModalidad className="h-3.5 w-3.5 text-gold" />
                          <span>{meta.etiqueta}</span>
                        </span>

                        {fechaVigencia && (
                          <span className="inline-flex items-center gap-1 rounded-full bg-[#0A2540]/80 backdrop-blur-sm px-2.5 py-1 text-[11px] font-medium text-white/90">
                            <Calendar className="h-3 w-3 text-gold" />
                            <span>Hasta {fechaVigencia}</span>
                          </span>
                        )}
                      </div>
                    </div>

                    {/* Cuerpo */}
                    <div className="p-6 sm:p-7 flex-1 flex flex-col">
                      <h3 className="text-xl font-bold text-[#0A2540] mb-2.5 leading-snug tracking-tight group-hover:text-[#153A59] transition-colors">
                        {curso.titulo}
                      </h3>

                      <p className="text-sm text-slate-600 leading-relaxed mb-5 line-clamp-3">
                        {curso.resumen}
                      </p>

                      {tieneMeta && (
                        <div className="mt-auto space-y-2.5 rounded-xl bg-stone-50/90 border border-stone-200/75 p-4 text-[13px] text-slate-700">
                          {fechaInicio && (
                            <div className="flex items-start gap-2.5">
                              <CalendarDays className="h-4 w-4 text-gold-text shrink-0 mt-0.5" />
                              <div>
                                <span className="font-semibold text-[#0A2540]">Inicio: </span>
                                <span>{fechaInicio}</span>
                              </div>
                            </div>
                          )}
                          {curso.duracion && (
                            <div className="flex items-start gap-2.5">
                              <Hourglass className="h-4 w-4 text-gold-text shrink-0 mt-0.5" />
                              <div>
                                <span className="font-semibold text-[#0A2540]">Duración: </span>
                                <span>{curso.duracion}</span>
                              </div>
                            </div>
                          )}
                          {curso.horario && (
                            <div className="flex items-start gap-2.5">
                              <Clock className="h-4 w-4 text-gold-text shrink-0 mt-0.5" />
                              <div>
                                <span className="font-semibold text-[#0A2540]">Horario: </span>
                                <span>{curso.horario}</span>
                              </div>
                            </div>
                          )}
                          {curso.certificacion && (
                            <div className="flex items-start gap-2.5">
                              <Award className="h-4 w-4 text-gold-text shrink-0 mt-0.5" />
                              <div>
                                <span className="font-semibold text-[#0A2540]">Incluye: </span>
                                <span>{curso.certificacion}</span>
                              </div>
                            </div>
                          )}
                        </div>
                      )}

                      {/* Acciones */}
                      <div className="mt-6 pt-4 border-t border-stone-100 flex flex-wrap items-center gap-2.5">
                        <button
                          type="button"
                          onClick={() => consultarCursoEnChat(curso)}
                          className="inline-flex h-10 items-center justify-center rounded-xl border border-stone-300 bg-white px-4 text-xs font-semibold text-[#0A2540] hover:border-[#0A2540] hover:bg-stone-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#0A2540] focus-visible:ring-offset-2 transition-colors"
                        >
                          Consultar curso
                        </button>

                        {tieneDetalles && (
                          <button
                            type="button"
                            onClick={() => setCursoDetalle(curso)}
                            className="inline-flex h-10 items-center justify-center rounded-xl border border-stone-200 bg-stone-50 px-3.5 text-xs font-semibold text-[#0A2540] hover:border-[#0A2540] hover:bg-stone-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#0A2540] focus-visible:ring-offset-2 transition-colors"
                          >
                            Ver detalles
                          </button>
                        )}

                        <a
                          href={`https://wa.me/${CONTACT_INFO.phoneClean}?text=${encodeURIComponent(`Hola, deseo solicitar matrícula para el curso: ${curso.titulo}.`)}`}
                          target="_blank"
                          rel="noopener noreferrer"
                          className="inline-flex h-10 flex-1 items-center justify-center gap-2 rounded-xl bg-[#0A2540] px-4 text-xs font-semibold text-white hover:bg-gold hover:text-[#0A2540] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#0A2540] focus-visible:ring-offset-2 transition-colors shadow-sm"
                        >
                          <MessageCircle className="h-3.5 w-3.5" />
                          <span>Solicitar matrícula</span>
                        </a>
                      </div>
                    </div>
                  </article>
                );
              })}
            </div>
          )}
        </div>
      </section>

      {/* Modal de detalles del curso */}
      {cursoDetalle && (
        <div
          role="dialog"
          aria-modal="true"
          aria-labelledby="curso-detalle-titulo"
          className="fixed inset-0 z-50 flex items-center justify-center bg-[#0A2540]/60 backdrop-blur-xs p-4"
          onClick={() => setCursoDetalle(null)}
        >
          <div
            className="relative w-full max-w-2xl max-h-[90vh] overflow-y-auto rounded-2xl bg-white shadow-2xl border border-stone-200"
            onClick={(e) => e.stopPropagation()}
          >
            <button
              type="button"
              onClick={() => setCursoDetalle(null)}
              aria-label="Cerrar detalles del curso"
              className="absolute right-4 top-4 z-10 flex h-9 w-9 items-center justify-center rounded-full bg-[#0A2540]/85 text-white hover:bg-[#0A2540] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-white transition-colors"
            >
              <X className="h-4 w-4" />
            </button>

            {cursoDetalle.imagen_url ? (
              <div className="relative aspect-[16/9] w-full overflow-hidden bg-[#0A2540]">
                {/* eslint-disable-next-line @next/next/no-img-element */}
                <img src={cursoDetalle.imagen_url} alt={cursoDetalle.titulo} className="h-full w-full object-cover" />
              </div>
            ) : null}

            <div className="p-6 sm:p-8 space-y-5">
              <div>
                <span className="inline-flex items-center gap-1.5 rounded-full bg-[#0A2540]/8 border border-[#0A2540]/15 px-3 py-1 text-xs font-semibold text-[#0A2540] mb-3">
                  {(MODALIDAD_META[cursoDetalle.modalidad] ?? MODALIDAD_META.presencial).etiqueta}
                </span>
                <h3 id="curso-detalle-titulo" className="text-2xl font-bold text-[#0A2540] leading-snug">
                  {cursoDetalle.titulo}
                </h3>
                <p className="mt-2.5 text-sm text-slate-600 leading-relaxed">{cursoDetalle.resumen}</p>
              </div>

              {(cursoDetalle.fecha_inicio || cursoDetalle.duracion || cursoDetalle.horario || cursoDetalle.certificacion) && (
                <div className="grid gap-3 sm:grid-cols-2 rounded-xl bg-stone-50 border border-stone-200/80 p-4 text-[13px] text-slate-700">
                  {cursoDetalle.fecha_inicio && formatearFechaCorta(cursoDetalle.fecha_inicio) && (
                    <div className="flex items-start gap-2.5">
                      <CalendarDays className="h-4 w-4 text-gold-text shrink-0 mt-0.5" />
                      <div>
                        <span className="font-semibold text-[#0A2540]">Inicio: </span>
                        <span>{formatearFechaCorta(cursoDetalle.fecha_inicio)}</span>
                      </div>
                    </div>
                  )}
                  {cursoDetalle.duracion && (
                    <div className="flex items-start gap-2.5">
                      <Hourglass className="h-4 w-4 text-gold-text shrink-0 mt-0.5" />
                      <div>
                        <span className="font-semibold text-[#0A2540]">Duración: </span>
                        <span>{cursoDetalle.duracion}</span>
                      </div>
                    </div>
                  )}
                  {cursoDetalle.horario && (
                    <div className="flex items-start gap-2.5">
                      <Clock className="h-4 w-4 text-gold-text shrink-0 mt-0.5" />
                      <div>
                        <span className="font-semibold text-[#0A2540]">Horario: </span>
                        <span>{cursoDetalle.horario}</span>
                      </div>
                    </div>
                  )}
                  {cursoDetalle.certificacion && (
                    <div className="flex items-start gap-2.5">
                      <Award className="h-4 w-4 text-gold-text shrink-0 mt-0.5" />
                      <div>
                        <span className="font-semibold text-[#0A2540]">Incluye: </span>
                        <span>{cursoDetalle.certificacion}</span>
                      </div>
                    </div>
                  )}
                </div>
              )}

              {cursoDetalle.temario && (
                <div>
                  <h4 className="text-sm font-bold text-[#0A2540] mb-2.5">Temario / lo que aprenderás</h4>
                  <ul className="space-y-2 text-sm text-slate-700">
                    {cursoDetalle.temario
                      .split(/\r?\n/)
                      .map((linea) => linea.trim())
                      .filter(Boolean)
                      .map((linea, idx) => (
                        <li key={idx} className="flex items-start gap-2.5">
                          <Check className="h-4 w-4 text-gold-text shrink-0 mt-0.5" />
                          <span>{linea.replace(/^[-•*]\s*/, '')}</span>
                        </li>
                      ))}
                  </ul>
                </div>
              )}

              {cursoDetalle.inscripcion && (
                <div className="rounded-xl border border-gold/35 bg-gold/[0.06] p-4 text-sm text-slate-700">
                  <div className="flex items-start gap-2.5">
                    <CalendarCheck className="h-4 w-4 text-gold-text shrink-0 mt-0.5" />
                    <div>
                      <span className="font-semibold text-[#0A2540]">Cómo inscribirse: </span>
                      <span>{cursoDetalle.inscripcion}</span>
                    </div>
                  </div>
                </div>
              )}

              <div className="pt-4 border-t border-stone-100 flex flex-col sm:flex-row items-stretch sm:items-center gap-3">
                <button
                  type="button"
                  onClick={() => consultarCursoEnChat(cursoDetalle)}
                  className="inline-flex h-11 items-center justify-center rounded-xl border border-stone-300 bg-white px-5 text-sm font-semibold text-[#0A2540] hover:border-[#0A2540] hover:bg-stone-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#0A2540] focus-visible:ring-offset-2 transition-colors"
                >
                  Consultar curso
                </button>
                <a
                  href={`https://wa.me/${CONTACT_INFO.phoneClean}?text=${encodeURIComponent(`Hola, deseo solicitar matrícula para el curso: ${cursoDetalle.titulo}.`)}`}
                  target="_blank"
                  rel="noopener noreferrer"
                  className="inline-flex h-11 flex-1 items-center justify-center gap-2 rounded-xl bg-[#0A2540] px-5 text-sm font-semibold text-white hover:bg-gold hover:text-[#0A2540] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#0A2540] focus-visible:ring-offset-2 transition-colors shadow-sm"
                >
                  <MessageCircle className="h-4 w-4" />
                  <span>Solicitar matrícula</span>
                </a>
              </div>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
