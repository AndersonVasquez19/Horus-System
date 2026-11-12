'use client';

import { useState, Suspense } from 'react';
import { useSearchParams } from 'next/navigation';
import Link from 'next/link';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { ArrowLeft, CheckCircle2, GraduationCap, ShieldAlert } from 'lucide-react';
import { CURSOS_CATALOGO } from '@/data/cursos';
import { CONTACT_INFO } from '@/data/contactos';

const matriculaSchema = z.object({
  curso: z.string().min(1, 'Selecciona un curso de interés'),
  nombres: z.string().min(2, 'Ingresa tus nombres completos'),
  apellidos: z.string().min(2, 'Ingresa tus apellidos completos'),
  email: z.string().email('Ingresa un correo electrónico válido'),
  telefono: z.string().min(7, 'Ingresa un teléfono o celular válido'),
  mensaje: z.string().max(500).optional(),
});

type MatriculaFormData = z.infer<typeof matriculaSchema>;

function MatriculaFormInner() {
  const searchParams = useSearchParams();
  const cursoParam = searchParams.get('curso') || '';

  const [ticketEnviado, setTicketEnviado] = useState<{
    codigo: string;
    datos: MatriculaFormData;
  } | null>(null);

  const {
    register,
    handleSubmit,
    formState: { errors, isSubmitting },
  } = useForm<MatriculaFormData>({
    resolver: zodResolver(matriculaSchema),
    defaultValues: {
      curso: cursoParam,
    },
  });

  const onSubmit = (data: MatriculaFormData) => {
    const aleatorio = Math.floor(100000 + Math.random() * 900000);
    const codigo = `MAT-${new Date().getFullYear()}-${aleatorio}`;
    setTicketEnviado({
      codigo,
      datos: data,
    });
  };

  if (ticketEnviado) {
    return (
      <div className="rounded-3xl border border-emerald-500/30 bg-[#0A1929]/90 backdrop-blur-2xl p-8 sm:p-10 shadow-2xl text-stone-200 animate-in fade-in zoom-in-95 duration-300">
        <div className="flex items-center gap-4 border-b border-white/10 pb-6 mb-6">
          <div className="flex h-14 w-14 items-center justify-center rounded-2xl bg-emerald-500/10 text-emerald-400 border border-emerald-500/20">
            <CheckCircle2 className="h-8 w-8" />
          </div>
          <div>
            <span className="text-xs font-bold uppercase tracking-wider text-emerald-400">
              Solicitud Registrada Exitosamente
            </span>
            <h3 className="text-2xl font-extrabold text-white">
              Código de Solicitud: <span className="text-gold">{ticketEnviado.codigo}</span>
            </h3>
          </div>
        </div>

        <div className="space-y-4 text-sm">
          <p className="text-stone-300">
            Hola <strong className="text-white">{ticketEnviado.datos.nombres} {ticketEnviado.datos.apellidos}</strong>, hemos recibido tu interés para el programa:
          </p>

          <div className="rounded-2xl border border-gold/30 bg-gold/10 p-4">
            <span className="text-xs text-gold uppercase font-semibold">Curso Seleccionado:</span>
            <h4 className="text-base font-bold text-white mt-1">{ticketEnviado.datos.curso}</h4>
          </div>

          <p className="text-xs text-stone-400 leading-relaxed">
            Un coordinador académico de Horus Group SRL se comunicará contigo a la brevedad vía WhatsApp o correo electrónico (<strong className="text-stone-300">{ticketEnviado.datos.email}</strong>) para brindarte los detalles de fechas, temarios y facilidades de pago.
          </p>

          <div className="rounded-xl border border-white/10 bg-white/5 p-4 flex items-start gap-3 text-xs text-stone-300">
            <ShieldAlert className="h-5 w-5 text-gold flex-shrink-0 mt-0.5" />
            <span>Nota: El registro de solicitud es informativo y no reserva cupo definitivo hasta la confirmación de la inscripción.</span>
          </div>
        </div>

        <div className="mt-8 flex flex-wrap gap-4 pt-6 border-t border-white/10">
          <Link
            href="/educacion/cursos"
            className="rounded-full bg-gold px-6 py-2.5 text-xs font-bold text-ink-dark shadow-gold hover:brightness-110"
          >
            Volver a Cursos
          </Link>
          <a
            href={`https://wa.me/${CONTACT_INFO.phoneClean}?text=${encodeURIComponent(`Hola, acabo de registrar mi solicitud de matrícula con código ${ticketEnviado.codigo} para el curso ${ticketEnviado.datos.curso}.`)}`}
            target="_blank"
            rel="noopener noreferrer"
            className="rounded-full border border-white/20 bg-white/10 px-6 py-2.5 text-xs font-semibold text-white hover:bg-white/20"
          >
            Acelerar por WhatsApp
          </a>
        </div>
      </div>
    );
  }

  return (
    <div className="rounded-3xl border border-white/10 bg-[#0A1929]/80 backdrop-blur-2xl p-6 sm:p-10 shadow-2xl">
      <header className="mb-8">
        <Link
          href="/educacion/cursos"
          className="inline-flex items-center gap-2 text-xs font-semibold text-gold hover:underline mb-4"
        >
          <ArrowLeft className="h-4 w-4" />
          <span>Volver al catálogo de cursos</span>
        </Link>
        <span className="block text-xs font-bold tracking-widest text-gold uppercase">Pre-Inscripción</span>
        <h1 className="text-2xl sm:text-3xl font-extrabold text-white mt-1">Solicita tu Matrícula</h1>
        <p className="text-xs sm:text-sm text-stone-300 mt-2">
          Completa tus datos y nuestro equipo académico se comunicará contigo para orientarte y confirmar los requisitos.
        </p>
      </header>

      <form onSubmit={handleSubmit(onSubmit)} className="space-y-6">
        <div>
          <label className="block text-xs font-semibold text-stone-300 mb-1.5">
            Curso de Interés *
          </label>
          <select
            {...register('curso')}
            className="w-full rounded-xl border border-white/10 bg-[#071321] px-4 py-3 text-sm text-white focus:border-gold focus:outline-none"
          >
            <option value="">Selecciona un curso de la lista</option>
            {CURSOS_CATALOGO.map((c) => (
              <option key={c.id} value={c.titulo}>
                {c.titulo} · ({c.modalidad.toUpperCase()})
              </option>
            ))}
          </select>
          {errors.curso && <p className="mt-1 text-xs text-red-400">{errors.curso.message}</p>}
        </div>

        <div className="grid gap-4 sm:grid-cols-2">
          <div>
            <label className="block text-xs font-semibold text-stone-300 mb-1.5">
              Nombres Completos *
            </label>
            <input
              type="text"
              {...register('nombres')}
              placeholder="Tus nombres"
              className="w-full rounded-xl border border-white/10 bg-[#071321] px-4 py-3 text-sm text-white focus:border-gold focus:outline-none"
            />
            {errors.nombres && <p className="mt-1 text-xs text-red-400">{errors.nombres.message}</p>}
          </div>

          <div>
            <label className="block text-xs font-semibold text-stone-300 mb-1.5">
              Apellidos Completos *
            </label>
            <input
              type="text"
              {...register('apellidos')}
              placeholder="Tus apellidos"
              className="w-full rounded-xl border border-white/10 bg-[#071321] px-4 py-3 text-sm text-white focus:border-gold focus:outline-none"
            />
            {errors.apellidos && <p className="mt-1 text-xs text-red-400">{errors.apellidos.message}</p>}
          </div>

          <div>
            <label className="block text-xs font-semibold text-stone-300 mb-1.5">
              Correo Electrónico *
            </label>
            <input
              type="email"
              {...register('email')}
              placeholder="correo@ejemplo.com"
              className="w-full rounded-xl border border-white/10 bg-[#071321] px-4 py-3 text-sm text-white focus:border-gold focus:outline-none"
            />
            {errors.email && <p className="mt-1 text-xs text-red-400">{errors.email.message}</p>}
          </div>

          <div>
            <label className="block text-xs font-semibold text-stone-300 mb-1.5">
              Teléfono / WhatsApp *
            </label>
            <input
              type="tel"
              {...register('telefono')}
              placeholder="Ej. 972021374"
              className="w-full rounded-xl border border-white/10 bg-[#071321] px-4 py-3 text-sm text-white focus:border-gold focus:outline-none"
            />
            {errors.telefono && <p className="mt-1 text-xs text-red-400">{errors.telefono.message}</p>}
          </div>
        </div>

        <div>
          <label className="block text-xs font-semibold text-stone-300 mb-1.5">
            Consulta o Comentario Adicional (Opcional)
          </label>
          <textarea
            rows={3}
            {...register('mensaje')}
            placeholder="¿Tienes alguna duda sobre horarios, pagos o temarios?"
            className="w-full rounded-xl border border-white/10 bg-[#071321] p-4 text-sm text-white focus:border-gold focus:outline-none"
          />
        </div>

        <div className="pt-4 border-t border-white/10 flex flex-wrap items-center justify-between gap-4">
          <span className="text-xs text-stone-400">
            Respuesta estimada: <strong>Menos de 24 horas</strong>.
          </span>
          <button
            type="submit"
            disabled={isSubmitting}
            className="rounded-full bg-gradient-to-r from-gold to-gold-dark px-8 py-3.5 text-sm font-bold text-ink-dark shadow-gold hover:brightness-110 transition-all hover:scale-105 disabled:opacity-50"
          >
            {isSubmitting ? 'Registrando...' : 'Enviar Solicitud'}
          </button>
        </div>
      </form>
    </div>
  );
}

export default function SolicitarMatriculaPage() {
  return (
    <div className="pt-28 pb-20">
      <div className="mx-auto max-w-3xl px-4 sm:px-6 lg:px-8 py-12">
        <Suspense fallback={<div className="text-center py-20 text-gold">Cargando formulario...</div>}>
          <MatriculaFormInner />
        </Suspense>
      </div>
    </div>
  );
}
