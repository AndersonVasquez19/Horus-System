import type { Metadata } from 'next';
import Link from 'next/link';
import { GraduationCap, ShoppingBag, ShieldCheck, Mail, AlertTriangle } from 'lucide-react';
import { CONTACT_INFO } from '@/data/contactos';

export const metadata: Metadata = {
  title: 'Políticas de Devolución y Garantías',
  description: 'Términos y condiciones para devoluciones, cancelaciones y garantías en Horus Group SRL.',
};

export default function PoliticasDevolucionPage() {
  return (
    <div className="pt-28 pb-20">
      <section className="relative overflow-hidden py-16 sm:py-20 bg-gradient-to-b from-[#0A1929] to-[#0A2540]">
        <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 text-center max-w-3xl mx-auto">
          <span className="text-xs font-bold tracking-widest text-gold uppercase">Términos Comerciales</span>
          <h1 className="mt-2 text-3xl sm:text-5xl font-extrabold text-white tracking-tight leading-tight">
            Políticas de <span className="text-gold">Devolución</span>
          </h1>
          <p className="mt-4 text-sm sm:text-base text-stone-300 leading-relaxed">
            Conoce las condiciones, plazos y procedimientos para solicitar cambios, devoluciones y garantías de nuestros cursos y servicios tecnológicos.
          </p>
        </div>
      </section>

      <div className="mx-auto max-w-4xl px-4 sm:px-6 lg:px-8 py-12 space-y-10">
        <div className="rounded-3xl border border-white/10 bg-[#0A1929]/70 backdrop-blur-xl p-8 shadow-xl text-sm leading-relaxed text-stone-300 space-y-4">
          <p>
            Al acceder y utilizar este sitio web, usted acepta todos los términos y condiciones de <strong>Horus Group SRL</strong>. Si no está de acuerdo con alguna cláusula, puede desistir de la utilización de nuestros servicios o contactarnos para absolver sus consultas.
          </p>
        </div>

        {/* Sección A: Educación */}
        <div className="rounded-3xl border border-white/10 bg-[#0A1929]/70 backdrop-blur-xl p-8 sm:p-10 shadow-xl space-y-6">
          <div className="flex items-center gap-3 border-b border-white/10 pb-4">
            <div className="flex h-12 w-12 items-center justify-center rounded-2xl bg-gold/10 text-gold border border-gold/30">
              <GraduationCap className="h-6 w-6" />
            </div>
            <div>
              <span className="text-xs font-bold text-gold uppercase tracking-wider">Sección A</span>
              <h2 className="text-2xl font-bold text-white">Educación y Capacitaciones</h2>
              <p className="text-xs text-stone-400">Talleres, cursos de actualización y diplomados</p>
            </div>
          </div>

          <div className="space-y-4 text-sm text-stone-300 leading-relaxed">
            <h3 className="text-lg font-bold text-white">1. Circunstancias para Solicitar Devolución</h3>
            <p>
              Casos fortuitos o de fuerza mayor debidamente acreditados que impidan la asistencia a la clase teórica y/o práctica, modificaciones no informadas en las características del programa o incumplimiento injustificado en las fechas programadas por la institución.
            </p>

            <h3 className="text-lg font-bold text-white pt-2">2. Plazos y Consideraciones</h3>
            <ul className="list-disc list-inside space-y-2 pl-2 text-stone-300">
              <li>Puedes solicitar la devolución hasta un plazo máximo de <strong>1 día calendario</strong> desde la inscripción y antes de la fecha de inicio de la primera clase teórica o práctica.</li>
              <li>La solicitud debe contener los <strong>medios probatorios</strong> que sustenten la imposibilidad fortuita.</li>
              <li>Una vez asistido a la primera sesión de clase, <strong>no procede</strong> la devolución económica.</li>
            </ul>

            <h3 className="text-lg font-bold text-white pt-2">3. Procedimiento de Solicitud</h3>
            <p>
              Envía un correo electrónico a <a href={`mailto:${CONTACT_INFO.email}`} className="text-gold font-semibold underline">{CONTACT_INFO.email}</a> indicando en el asunto: <code className="bg-white/10 px-2 py-0.5 rounded text-white font-mono text-xs">DEVOLUCIÓN DE DINERO</code>, adjuntando DNI, comprobante de pago y motivo. La respuesta de nuestra área legal se emitirá en un plazo máximo de <strong>2 días hábiles</strong>.
            </p>
          </div>
        </div>

        {/* Sección B: Equipos y Tecnología */}
        <div className="rounded-3xl border border-white/10 bg-[#0A1929]/70 backdrop-blur-xl p-8 sm:p-10 shadow-xl space-y-6">
          <div className="flex items-center gap-3 border-b border-white/10 pb-4">
            <div className="flex h-12 w-12 items-center justify-center rounded-2xl bg-gold/10 text-gold border border-gold/30">
              <ShoppingBag className="h-6 w-6" />
            </div>
            <div>
              <span className="text-xs font-bold text-gold uppercase tracking-wider">Sección B</span>
              <h2 className="text-2xl font-bold text-white">Equipos y Servicios Tecnológicos</h2>
              <p className="text-xs text-stone-400">Garantías de hardware y repuestos informáticos</p>
            </div>
          </div>

          <div className="space-y-4 text-sm text-stone-300 leading-relaxed">
            <h3 className="text-lg font-bold text-white">1. Garantía Técnica</h3>
            <p>
              Todos los equipos de videovigilancia, redes y componentes de cómputo comercializados cuentan con garantía oficial por defectos de fabricación. La garantía no cubre daños por fluctuaciones eléctricas, mala manipulación, golpes o ingreso de líquidos.
            </p>

            <h3 className="text-lg font-bold text-white pt-2">2. Plazo de Cambio Inmediato</h3>
            <p>
              Si un producto presenta fallas técnicas de fábrica dentro de los primeros <strong>7 días calendario</strong> desde su recepción, se procederá al cambio directo por un equipo nuevo idéntico o nota de crédito tras la revisión técnica correspondiente.
            </p>
          </div>
        </div>
      </div>
    </div>
  );
}
