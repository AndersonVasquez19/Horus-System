import type { Metadata } from 'next';
import Image from 'next/image';
import {
  Award,
  UserCheck,
  Handshake,
  TrendingUp,
  Users,
  GraduationCap,
  Briefcase,
  Scale,
  CheckCircle2,
  Clock,
  RefreshCw,
  HeartHandshake,
  Puzzle,
  Quote,
} from 'lucide-react';

export const metadata: Metadata = {
  title: 'Asesoramiento Profesional',
  description: 'Asesoramiento educativo, empresarial y legal en Cajamarca - Horus Group SRL.',
};

export default function AsesoramientoPage() {
  return (
    <div className="pt-20">
      {/* 1. Intro Asesoramiento Header con degradado institucional y lineShine */}
      <section className="relative overflow-hidden py-16 sm:py-24 bg-gradient-to-br from-[#0A2540] via-[#102d49] to-[#1a2f45] text-white">
        <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
          <div className="max-w-3xl">
            <div className="inline-flex items-center gap-2 px-6 py-2 rounded-full border-2 border-gold bg-gold/20 mb-8">
              <Award className="h-4 w-4 text-gold" />
              <span className="text-xs sm:text-sm font-semibold tracking-wider text-gold uppercase">
                ASESORÍA PROFESIONAL
              </span>
            </div>

            <h2 className="relative pb-6 text-3xl sm:text-5xl font-bold tracking-tight text-white leading-tight">
              ASESORAMIENTO EDUCATIVO, EMPRESARIAL Y LEGAL
              <span className="absolute bottom-0 left-0 h-1 w-48 rounded-full animate-line-shine"></span>
            </h2>

            <p className="mt-6 text-base sm:text-lg text-white/90 leading-relaxed text-justify mb-10">
              Contamos con profesionales altamente calificados y con amplia experiencia en asesoramiento,
              comprometidos en brindarle orientación estratégica para el desarrollo de sus proyectos personales y
              profesionales, con el objetivo de alcanzar resultados exitosos y sostenibles.
            </p>

            {/* 3 Stats Boxes de Blade */}
            <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
              <div className="flex items-center gap-3.5 rounded-xl border border-white/15 bg-white/10 p-4 backdrop-blur-md">
                <UserCheck className="h-8 w-8 text-gold shrink-0" />
                <div>
                  <strong className="block text-base font-bold text-white">Expertos</strong>
                  <span className="text-xs text-white/80">Profesionales con amplia experiencia.</span>
                </div>
              </div>

              <div className="flex items-center gap-3.5 rounded-xl border border-white/15 bg-white/10 p-4 backdrop-blur-md">
                <Handshake className="h-8 w-8 text-gold shrink-0" />
                <div>
                  <strong className="block text-base font-bold text-white">Personalizado</strong>
                  <span className="text-xs text-white/80">Atención individualizada.</span>
                </div>
              </div>

              <div className="flex items-center gap-3.5 rounded-xl border border-white/15 bg-white/10 p-4 backdrop-blur-md">
                <TrendingUp className="h-8 w-8 text-gold shrink-0" />
                <div>
                  <strong className="block text-base font-bold text-white">Resultados</strong>
                  <span className="text-xs text-white/80">Garantizamos buenos resultados.</span>
                </div>
              </div>
            </div>
          </div>
        </div>
      </section>

      {/* 2. Sección Centro con Imagen Destacada + 3 Cartas en fondo blanco */}
      <section className="bg-white py-16 sm:py-24">
        <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 space-y-16">
          {/* Bloque Destacado */}
          <div className="grid gap-10 lg:grid-cols-2 items-center rounded-3xl border border-stone-200 bg-[#F7F9FC] p-8 sm:p-12 shadow-sm">
            <div>
              <div className="flex h-14 w-14 items-center justify-center rounded-2xl bg-[#0A2540] text-gold mb-6 shadow-md">
                <Users className="h-7 w-7" />
              </div>
              <p className="text-base sm:text-lg leading-relaxed text-slate-700 mb-6 text-justify">
                Contamos con personal altamente capacitado y con amplia experiencia en diversos rubros, preparado
                para brindarle el respaldo y la asesoría necesaria en el desarrollo y ejecución de su proyecto,
                garantizando un trabajo eficiente y de calidad.
              </p>
              <div className="inline-flex items-center gap-2 rounded-xl bg-white border border-stone-200 px-4 py-2.5 text-xs sm:text-sm font-semibold text-[#0A2540] shadow-xs">
                <GraduationCap className="h-4 w-4 text-gold" />
                <span>Profesionales con amplia experiencia.</span>
              </div>
            </div>

            <div className="relative h-64 sm:h-80 w-full rounded-2xl overflow-hidden border border-stone-200 shadow-lg">
              <Image
                src="/images/asesoramiento/asesoramiento2.jpg"
                alt="Asesoramiento en Horus Group"
                fill
                sizes="(max-width: 1024px) 100vw, 50vw"
                className="object-cover"
              />
            </div>
          </div>

          {/* 3 Cartas Principales */}
          <div className="grid gap-8 sm:grid-cols-2 lg:grid-cols-3">
            {/* Educativo */}
            <article className="card-hover-border rounded-2xl bg-white border border-stone-200 shadow-[0_8px_30px_rgba(0,0,0,0.08)] hover:-translate-y-3 hover:shadow-[0_15px_50px_rgba(0,0,0,0.15)] hover:border-gold transition-all duration-400 flex flex-col justify-between overflow-hidden">
              <div>
                <div className="relative h-52 w-full bg-stone-100 overflow-hidden">
                  <Image
                    src="/images/asesoramiento/asesoramiento-educativo.jpg"
                    alt="Asesoramiento Educativo"
                    fill
                    sizes="(max-width: 768px) 100vw, 400px"
                    className="object-cover hover:scale-105 transition-transform duration-500"
                  />
                </div>

                <div className="relative z-10 -mt-8 ml-6 flex h-14 w-14 items-center justify-center rounded-full border-4 border-white bg-gradient-to-br from-[#0A2540] to-gold shadow-md text-white">
                  <GraduationCap className="h-6 w-6" />
                </div>

                <div className="p-6 pt-3">
                  <h3 className="text-xl font-bold text-[#0A2540] mb-3 leading-snug">
                    ASESORAMIENTO EDUCATIVO
                  </h3>
                  <p className="text-sm text-slate-600 leading-relaxed mb-6">
                    Te ayudamos a estructurar, redactar y finalizar tu tesis o trabajo de grado con rigor académico y
                    claridad.
                  </p>
                  <div className="space-y-2 border-t border-stone-100 pt-4">
                    <div className="flex items-center gap-2 text-xs sm:text-sm text-slate-700 font-medium">
                      <CheckCircle2 className="h-4 w-4 text-gold shrink-0" />
                      <span>Metodología rigurosa.</span>
                    </div>
                    <div className="flex items-center gap-2 text-xs sm:text-sm text-slate-700 font-medium">
                      <CheckCircle2 className="h-4 w-4 text-gold shrink-0" />
                      <span>Acompañamiento continuo.</span>
                    </div>
                  </div>
                </div>
              </div>
            </article>

            {/* Empresarial */}
            <article className="card-hover-border rounded-2xl bg-white border border-stone-200 shadow-[0_8px_30px_rgba(0,0,0,0.08)] hover:-translate-y-3 hover:shadow-[0_15px_50px_rgba(0,0,0,0.15)] hover:border-gold transition-all duration-400 flex flex-col justify-between overflow-hidden">
              <div>
                <div className="relative h-52 w-full bg-stone-100 overflow-hidden">
                  <Image
                    src="/images/asesoramiento/asesoramiento-empresarial.jpg"
                    alt="Asesoramiento Empresarial"
                    fill
                    sizes="(max-width: 768px) 100vw, 400px"
                    className="object-cover hover:scale-105 transition-transform duration-500"
                  />
                </div>

                <div className="relative z-10 -mt-8 ml-6 flex h-14 w-14 items-center justify-center rounded-full border-4 border-white bg-gradient-to-br from-[#0A2540] to-gold shadow-md text-white">
                  <Briefcase className="h-6 w-6" />
                </div>

                <div className="p-6 pt-3">
                  <h3 className="text-xl font-bold text-[#0A2540] mb-3 leading-snug">
                    ASESORAMIENTO EMPRESARIAL
                  </h3>
                  <p className="text-sm text-slate-600 leading-relaxed mb-6">
                    Te brindamos las herramientas necesarias tanto en la parte económica (finanzas y proyectos) como en
                    la parte humana (liderazgo y equipo) para que tu organización sea sólida y exitosa.
                  </p>
                  <div className="space-y-2 border-t border-stone-100 pt-4">
                    <div className="flex items-center gap-2 text-xs sm:text-sm text-slate-700 font-medium">
                      <CheckCircle2 className="h-4 w-4 text-gold shrink-0" />
                      <span>Estrategias efectivas.</span>
                    </div>
                    <div className="flex items-center gap-2 text-xs sm:text-sm text-slate-700 font-medium">
                      <CheckCircle2 className="h-4 w-4 text-gold shrink-0" />
                      <span>Resultados medibles.</span>
                    </div>
                  </div>
                </div>
              </div>
            </article>

            {/* Legal */}
            <article className="card-hover-border rounded-2xl bg-white border border-stone-200 shadow-[0_8px_30px_rgba(0,0,0,0.08)] hover:-translate-y-3 hover:shadow-[0_15px_50px_rgba(0,0,0,0.15)] hover:border-gold transition-all duration-400 flex flex-col justify-between overflow-hidden">
              <div>
                <div className="relative h-52 w-full bg-stone-100 overflow-hidden">
                  <Image
                    src="/images/asesoramiento/asesoramiento-legal.jpg"
                    alt="Asesoramiento Legal"
                    fill
                    sizes="(max-width: 768px) 100vw, 400px"
                    className="object-cover hover:scale-105 transition-transform duration-500"
                  />
                </div>

                <div className="relative z-10 -mt-8 ml-6 flex h-14 w-14 items-center justify-center rounded-full border-4 border-white bg-gradient-to-br from-[#0A2540] to-gold shadow-md text-white">
                  <Scale className="h-6 w-6" />
                </div>

                <div className="p-6 pt-3">
                  <h3 className="text-xl font-bold text-[#0A2540] mb-3 leading-snug">
                    ASESORAMIENTO LEGAL
                  </h3>
                  <p className="text-sm text-slate-600 leading-relaxed mb-6">
                    Contamos con un equipo de expertos comprometidos en brindarte la tranquilidad y seguridad jurídica
                    que necesitas en los momentos más importantes.
                  </p>
                  <div className="space-y-2 border-t border-stone-100 pt-4">
                    <div className="flex items-center gap-2 text-xs sm:text-sm text-slate-700 font-medium">
                      <CheckCircle2 className="h-4 w-4 text-gold shrink-0" />
                      <span>Asesoría especializada.</span>
                    </div>
                    <div className="flex items-center gap-2 text-xs sm:text-sm text-slate-700 font-medium">
                      <CheckCircle2 className="h-4 w-4 text-gold shrink-0" />
                      <span>Respaldo profesional.</span>
                    </div>
                  </div>
                </div>
              </div>
            </article>
          </div>
        </div>
      </section>

      {/* 3. Sección Apoyo: Compromiso y Respaldo Profesional con Cita Final */}
      <section className="bg-gradient-to-b from-[#F7F9FC] to-white py-16 sm:py-24 border-t border-stone-200">
        <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
          <div className="text-center max-w-3xl mx-auto mb-16">
            <h2 className="relative inline-block pb-4 text-3xl sm:text-4xl md:text-5xl font-bold tracking-tight text-[#0A2540]">
              Compromiso y Respaldo Profesional
              <span className="absolute bottom-0 left-1/2 -translate-x-1/2 h-1 w-44 rounded-full animate-line-shine"></span>
            </h2>
            <p className="mt-4 text-base text-slate-600 leading-relaxed">
              En Horus Group S.R.L., convertimos nuestra experiencia en tu mayor tranquilidad. Te brindamos un
              acompañamiento técnico y profesional de alto nivel en cada uno de nuestros servicios.
            </p>
          </div>

          <div className="grid gap-6 sm:grid-cols-2 lg:grid-cols-4 mb-16">
            <div className="rounded-2xl border border-stone-200 bg-white p-6 shadow-sm hover:shadow-md transition-shadow">
              <Clock className="h-8 w-8 text-gold mb-4" />
              <h3 className="text-lg font-bold text-[#0A2540] mb-2">RESPUESTA ÁGIL</h3>
              <p className="text-xs sm:text-sm text-slate-600 leading-relaxed">
                Ante cualquier consulta, nuestro equipo de expertos te brindará orientación clara en un plazo
                máximo de 48 horas hábiles.
              </p>
            </div>

            <div className="rounded-2xl border border-stone-200 bg-white p-6 shadow-sm hover:shadow-md transition-shadow">
              <RefreshCw className="h-8 w-8 text-gold mb-4" />
              <h3 className="text-lg font-bold text-[#0A2540] mb-2">FLEXIBILIDAD TOTAL</h3>
              <p className="text-xs sm:text-sm text-slate-600 leading-relaxed">
                Nos adaptamos a tu ritmo y ubicación, ofreciendo respaldo continuo tanto en modalidad
                presencial como online.
              </p>
            </div>

            <div className="rounded-2xl border border-stone-200 bg-white p-6 shadow-sm hover:shadow-md transition-shadow">
              <HeartHandshake className="h-8 w-8 text-gold mb-4" />
              <h3 className="text-lg font-bold text-[#0A2540] mb-2">ACOMPAÑAMIENTO INTEGRAL</h3>
              <p className="text-xs sm:text-sm text-slate-600 leading-relaxed">
                Estamos contigo en todo el proceso; desde el nacimiento de tu idea y su implementación, hasta
                la medición final de los resultados.
              </p>
            </div>

            <div className="rounded-2xl border border-stone-200 bg-white p-6 shadow-sm hover:shadow-md transition-shadow">
              <Puzzle className="h-8 w-8 text-gold mb-4" />
              <h3 className="text-lg font-bold text-[#0A2540] mb-2">SOLUCIONES A MEDIDA</h3>
              <p className="text-xs sm:text-sm text-slate-600 leading-relaxed">
                Personalizamos cada asesoría para asegurar que se ajuste perfectamente a tus necesidades
                específicas.
              </p>
            </div>
          </div>

          {/* Cita Final de Blade */}
          <div className="rounded-3xl border border-gold/30 bg-[#0A2540] p-8 sm:p-12 text-center text-white max-w-4xl mx-auto shadow-xl">
            <Quote className="h-10 w-10 text-gold mx-auto mb-4 opacity-75" />
            <p className="text-lg sm:text-xl font-medium leading-relaxed italic text-white/95">
              &ldquo;Elegirnos significa elegir tranquilidad, profesionalismo y un respaldo constante que transforma
              tus dudas en soluciones concretas.&rdquo;
            </p>
          </div>
        </div>
      </section>
    </div>
  );
}
