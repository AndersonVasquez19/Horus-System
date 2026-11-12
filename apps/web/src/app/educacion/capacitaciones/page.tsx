import type { Metadata } from 'next';
import Image from 'next/image';
import {
  Star,
  Users,
  Clock,
  Award,
  Layers,
  Handshake,
  Check,
} from 'lucide-react';

export const metadata: Metadata = {
  title: 'Capacitaciones',
  description: 'Programas de capacitación y formación profesional en Cajamarca - Horus Group SRL.',
};

export default function CapacitacionesPage() {
  const programas = [
    {
      titulo: 'CAPACITACIONES EMPRESARIALES',
      descripcion:
        'Programas profesionales diseñados para los colaboradores de tu empresa o institución, manteniéndolos a la vanguardia de las competencias que exige el mercado actual.',
      imagen: '/images/capacitaciones/capacitaciones-empresariales.jpg',
      icon: Users,
      features: ['Programas personalizados.', 'Modalidad presencial/online.', 'Certificación incluida.'],
    },
    {
      titulo: 'FORMACIÓN PERSONALIZADA',
      descripcion:
        'Capacitaciones personales y grupales adaptadas a tus necesidades educativas, profesionales y laborales, con certificación que garantiza la efectividad del aprendizaje.',
      imagen: '/images/capacitaciones/formacion-personalizada.jpg',
      icon: Layers,
      features: ['Atención individualizada.', 'Horarios flexibles.', 'Seguimiento continuo.'],
    },
    {
      titulo: 'CERTIFICACIONES PROFESIONALES',
      descripcion:
        'Actualiza tus conocimientos teóricos y experiencias laborales mediante exámenes de certificación en diferentes áreas profesionales y laborales reconocidas.',
      imagen: '/images/capacitaciones/certificaciones-profesionales.jpg',
      icon: Award,
      features: ['Certificados oficiales.', 'Reconocimiento internacional.', 'Validez permanente.'],
    },
    {
      titulo: 'CONVENIOS INSTITUCIONALES',
      descripcion:
        'Establecemos alianzas estratégicas interinstitucionales para avanzar con un trabajo mancomunado, generando beneficios compartidos y oportunidades de crecimiento.',
      imagen: '/images/capacitaciones/convenios-institucionales.jpg',
      icon: Handshake,
      features: ['Alianzas estratégicas.', 'Beneficios exclusivos.', 'Red de contactos.'],
    },
  ];

  return (
    <div className="pt-20">
      {/* Intro Capacitaciones con degradado institucional y lineShine */}
      <section className="relative overflow-hidden py-16 sm:py-24 bg-gradient-to-br from-[#0A2540] via-[#102d49] to-[#1a2f45] text-white">
        <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
          <div className="max-w-3xl">
            <div className="inline-flex items-center gap-2 px-6 py-2.5 rounded-full border-2 border-gold bg-gold/20 mb-8">
              <Star className="h-4 w-4 text-gold fill-gold" />
              <span className="text-xs sm:text-sm font-semibold tracking-wider text-gold uppercase">
                FORMACIÓN PROFESIONAL
              </span>
            </div>

            <h2 className="relative pb-6 text-3xl sm:text-5xl font-bold tracking-tight text-white leading-tight">
              CAPACITACIONES
              <span className="absolute bottom-0 left-0 h-1 w-44 rounded-full animate-line-shine"></span>
            </h2>

            <p className="mt-6 text-base sm:text-lg text-white/90 leading-relaxed text-justify mb-10">
              Impulsamos el talento de su equipo para enfrentar los desafíos de un mercado en constante
              evolución. Diseñamos programas de formación estratégica que aseguran que sus colaboradores estén
              siempre a la vanguardia.
            </p>

            {/* 3 Stats Boxes de Blade */}
            <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
              <div className="flex items-center gap-4 p-4 rounded-xl border border-white/15 bg-white/10 backdrop-blur-md">
                <Users className="h-8 w-8 text-gold shrink-0" />
                <div>
                  <strong className="block text-xl font-bold text-white">+400</strong>
                  <span className="text-xs text-white/80">Profesionales Capacitados.</span>
                </div>
              </div>

              <div className="flex items-center gap-4 p-4 rounded-xl border border-white/15 bg-white/10 backdrop-blur-md">
                <Clock className="h-8 w-8 text-gold shrink-0" />
                <div>
                  <strong className="block text-xl font-bold text-white">Flexible</strong>
                  <span className="text-xs text-white/80">Horarios Adaptables.</span>
                </div>
              </div>

              <div className="flex items-center gap-4 p-4 rounded-xl border border-white/15 bg-white/10 backdrop-blur-md">
                <Award className="h-8 w-8 text-gold shrink-0" />
                <div>
                  <strong className="block text-xl font-bold text-white">100%</strong>
                  <span className="text-xs text-white/80">Certificación Garantizada.</span>
                </div>
              </div>
            </div>
          </div>
        </div>
      </section>

      {/* Nuestros Programas de Capacitación en fondo blanco con degradado claro */}
      <section className="bg-gradient-to-b from-white to-[#f8f9fa] py-16 sm:py-24">
        <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
          <div className="text-center mb-16">
            <h2 className="relative inline-block pb-4 text-3xl sm:text-4xl md:text-5xl font-bold tracking-tight text-[#0A2540]">
              NUESTROS PROGRAMAS DE CAPACITACIÓN
              <span className="absolute bottom-0 left-1/2 -translate-x-1/2 h-1 w-48 rounded-full animate-line-shine"></span>
            </h2>
            <p className="mt-4 text-base text-slate-600">
              Soluciones formativas adaptadas a tus necesidades.
            </p>
          </div>

          <div className="grid gap-8 sm:grid-cols-2 lg:grid-cols-2">
            {programas.map((prog, idx) => {
              const IconComponent = prog.icon;
              return (
                <article
                  key={idx}
                  className="card-hover-border rounded-2xl bg-white border border-stone-200 overflow-hidden shadow-[0_8px_30px_rgba(0,0,0,0.08)] hover:-translate-y-3 hover:shadow-[0_15px_50px_rgba(0,0,0,0.15)] hover:border-gold transition-all duration-400 flex flex-col justify-between"
                >
                  <div>
                    {/* Header Image */}
                    <div className="relative h-56 sm:h-64 w-full bg-stone-100 overflow-hidden">
                      <Image
                        src={prog.imagen}
                        alt={prog.titulo}
                        fill
                        sizes="(max-width: 1024px) 100vw, 50vw"
                        className="object-cover hover:scale-105 transition-transform duration-500"
                      />
                    </div>

                    {/* Circular Overlapping Icon */}
                    <div className="relative z-10 -mt-8 ml-8 flex h-16 w-16 items-center justify-center rounded-full border-4 border-white bg-gradient-to-br from-[#0A2540] to-gold shadow-md text-white">
                      <IconComponent className="h-7 w-7" />
                    </div>

                    <div className="p-8 pt-4">
                      <h3 className="text-xl sm:text-2xl font-bold text-[#0A2540] mb-3 leading-snug">
                        {prog.titulo}
                      </h3>

                      <p className="text-sm sm:text-base text-slate-600 leading-relaxed mb-6">
                        {prog.descripcion}
                      </p>

                      <div className="space-y-2.5 border-t border-stone-100 pt-5">
                        {prog.features.map((feat, fIdx) => (
                          <div key={fIdx} className="flex items-center gap-2.5 text-xs sm:text-sm text-slate-700 font-medium">
                            <Check className="h-4 w-4 text-gold shrink-0" />
                            <span>{feat}</span>
                          </div>
                        ))}
                      </div>
                    </div>
                  </div>
                </article>
              );
            })}
          </div>
        </div>
      </section>
    </div>
  );
}
