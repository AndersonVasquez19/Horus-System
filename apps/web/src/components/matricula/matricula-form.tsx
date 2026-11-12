'use client';

import { useCallback, useEffect, useRef, useState } from 'react';
import Link from 'next/link';
import { useSearchParams } from 'next/navigation';
import { useForm, useWatch, type FieldPath } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { AlertCircle, ArrowLeft, CheckCircle2, Info, Loader2, ShieldAlert } from 'lucide-react';
import { CONTACT_INFO } from '@/data/contactos';

/**
 * Solicitud de matrícula. Es un CONTACTO que el equipo académico revisa: no crea una matrícula ni reserva cupo. Laravel
 * valida y vuelve a comprobar que el curso siga publicado; este esquema solo adelanta los mismos controles. El código
 * de seguimiento es el que devuelve el servidor, nunca uno inventado aquí.
 */

const TELEFONO = /^[+0-9() .-]{7,30}$/;

const esquema = z
  .object({
    curso: z.string().min(1, 'Selecciona un curso de interés.'),
    nombres: z.string().trim().min(2, 'Ingresa tus nombres.').max(120),
    apellidos: z.string().trim().min(2, 'Ingresa tus apellidos.').max(120),
    email: z.string().trim().email('Ingresa un correo electrónico válido.').max(254),
    telefono: z.string().trim().regex(TELEFONO, 'Ingresa un teléfono válido (solo números, +, espacios o guiones).'),
    mensaje: z.string().trim().max(1000, 'Máximo 1000 caracteres.').optional(),
    es_menor: z.boolean(),
    representante_nombre: z.string().trim().max(120).optional(),
    representante_email: z.string().trim().optional(),
    representante_telefono: z.string().trim().optional(),
    consentimiento: z.boolean().refine((v) => v, 'Debes aceptar para que podamos atender tu solicitud.'),
  })
  .superRefine((d, ctx) => {
    if (!d.es_menor) return;
    if (!d.representante_nombre || d.representante_nombre.length < 2) ctx.addIssue({ code: 'custom', path: ['representante_nombre'], message: 'Ingresa el nombre del padre, madre o representante.' });
    if (!d.representante_email || !z.string().email().safeParse(d.representante_email).success) ctx.addIssue({ code: 'custom', path: ['representante_email'], message: 'Ingresa un correo válido del representante.' });
    if (!d.representante_telefono || !TELEFONO.test(d.representante_telefono)) ctx.addIssue({ code: 'custom', path: ['representante_telefono'], message: 'Ingresa un teléfono válido del representante.' });
  });

type Datos = z.infer<typeof esquema>;
type Campo = FieldPath<Datos>;

const CAMPOS_CONOCIDOS = new Set<string>(['curso', 'nombres', 'apellidos', 'email', 'telefono', 'mensaje', 'es_menor', 'representante_nombre', 'representante_email', 'representante_telefono', 'consentimiento']);

interface Curso {
  valor: string;
  titulo: string;
  resumen: string;
  modalidad: string;
  horario: string | null;
  inscripcion: string | null;
  vigente_hasta: string;
}

type Estado =
  | { estado: 'cargando' }
  | { estado: 'error' }
  | { estado: 'deshabilitado' }
  | { estado: 'listo'; marca: string; cursos: Curso[] };

interface Confirmacion {
  codigo: string;
  curso: string;
  repetida: boolean;
  email: string;
}

const input =
  'w-full rounded-xl border border-white/10 bg-[#071321] px-4 py-3 text-sm text-white placeholder:text-stone-500 focus:border-gold focus:outline-none focus-visible:ring-2 focus-visible:ring-gold/40 aria-[invalid=true]:border-red-400';

function fechaLarga(iso: string): string {
  const [a, m, d] = iso.split('-').map(Number);
  return new Date(a, m - 1, d).toLocaleDateString('es-PE', { year: 'numeric', month: 'long', day: 'numeric' });
}

async function pedirFormulario(): Promise<Estado> {
  try {
    const r = await fetch('/api/matriculas', { cache: 'no-store', signal: AbortSignal.timeout(12_000) });
    if (!r.ok) return { estado: 'error' };
    const f = await r.json();
    if (f?.habilitado === true && typeof f.marca === 'string' && Array.isArray(f.cursos) && f.cursos.length > 0) {
      return { estado: 'listo', marca: f.marca, cursos: f.cursos as Curso[] };
    }
    return { estado: 'deshabilitado' };
  } catch {
    return { estado: 'error' };
  }
}

export function MatriculaForm() {
  const cursoParam = useSearchParams().get('curso') ?? '';
  const [formulario, setFormulario] = useState<Estado>({ estado: 'cargando' });
  const [confirmacion, setConfirmacion] = useState<Confirmacion | null>(null);
  const [aviso, setAviso] = useState<string | null>(null);
  const [trampa, setTrampa] = useState('');
  const enviando = useRef(false);
  const avisoRef = useRef<HTMLDivElement>(null);
  const confirmacionRef = useRef<HTMLDivElement>(null);

  const {
    register,
    handleSubmit,
    control,
    getValues,
    setError,
    setValue,
    reset,
    formState: { errors, isSubmitting },
  } = useForm<Datos>({
    resolver: zodResolver(esquema),
    defaultValues: { curso: '', es_menor: false, consentimiento: false },
  });

  const cursoElegido = useWatch({ control, name: 'curso' });
  const esMenor = useWatch({ control, name: 'es_menor' });

  const aplicar = setFormulario;

  const recargar = useCallback(async () => aplicar(await pedirFormulario()), [aplicar]);

  useEffect(() => {
    let vigente = true;
    void pedirFormulario().then((e) => {
      if (vigente) aplicar(e);
    });
    return () => {
      vigente = false;
    };
  }, [aplicar]);

  // Con las opciones ya pintadas: conserva el curso elegido si sigue publicado; si no, preselecciona por ?curso=
  // (título o referencia) o limpia la selección.
  useEffect(() => {
    if (formulario.estado !== 'listo') return;
    if (formulario.cursos.some((c) => c.valor === getValues('curso'))) return;
    const pedido = cursoParam.trim().toLowerCase();
    const previo = formulario.cursos.find((c) => pedido !== '' && (c.valor === pedido || c.titulo.toLowerCase() === pedido));
    setValue('curso', previo?.valor ?? '');
  }, [formulario, cursoParam, getValues, setValue]);

  const mostrarAviso = (texto: string) => {
    setAviso(texto);
    requestAnimationFrame(() => avisoRef.current?.focus());
  };

  const onSubmit = async (d: Datos) => {
    if (enviando.current || formulario.estado !== 'listo') return;
    enviando.current = true;
    setAviso(null);
    const cuerpo: Record<string, unknown> = {
      curso: d.curso, nombres: d.nombres, apellidos: d.apellidos, email: d.email, telefono: d.telefono,
      mensaje: d.mensaje ?? '', es_menor: d.es_menor, consentimiento: d.consentimiento, marca: formulario.marca, sitio_web: trampa,
    };
    if (d.es_menor) Object.assign(cuerpo, { representante_nombre: d.representante_nombre, representante_email: d.representante_email, representante_telefono: d.representante_telefono });
    const cursoTitulo = formulario.cursos.find((c) => c.valor === d.curso)?.titulo ?? '';

    try {
      const r = await fetch('/api/matriculas', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify(cuerpo),
        cache: 'no-store',
        signal: AbortSignal.timeout(25_000),
      });
      const respuesta = await r.json().catch(() => null);

      if (r.ok && typeof respuesta?.codigo === 'string') {
        setConfirmacion({ codigo: respuesta.codigo, curso: String(respuesta.curso ?? cursoTitulo), repetida: respuesta.repetida === true, email: d.email });
        requestAnimationFrame(() => confirmacionRef.current?.focus());
        return;
      }
      if (r.status === 422) {
        const porCampo: Record<string, string[]> = respuesta?.errors ?? {};
        let renovar = false;
        for (const [campo, mensajes] of Object.entries(porCampo)) {
          if (campo === 'marca' || campo === 'sitio_web' || campo === 'curso') renovar = true;
          if (CAMPOS_CONOCIDOS.has(campo)) setError(campo as Campo, { type: 'server', message: mensajes[0] });
        }
        if (renovar) {
          await recargar();
          mostrarAviso(porCampo.curso ? 'Ese curso ya no está disponible. Revisa la lista y elige uno de los cursos publicados.' : 'El formulario caducó o se envió demasiado rápido. Tus datos siguen aquí: espera unos segundos y vuelve a enviar.');
        } else {
          mostrarAviso('Revisa los campos marcados. Tus datos no se han perdido.');
        }
        return;
      }
      if (r.status === 429) {
        const minutos = Math.max(1, Math.ceil(Number(r.headers.get('retry-after') ?? '600') / 60));
        mostrarAviso(`Se alcanzó el límite de solicitudes. Podrás volver a intentarlo en unos ${minutos} minuto(s). Tus datos siguen en el formulario.`);
        return;
      }
      mostrarAviso('No pudimos registrar tu solicitud en este momento. Tus datos siguen aquí; puedes volver a intentarlo o escribirnos por WhatsApp.');
    } catch {
      mostrarAviso('No pudimos conectar con el servidor. Tus datos siguen aquí; puedes reintentar sin riesgo de duplicar tu solicitud.');
    } finally {
      enviando.current = false;
    }
  };

  const nueva = () => {
    setConfirmacion(null);
    setAviso(null);
    reset({ curso: '', es_menor: false, consentimiento: false });
    setFormulario({ estado: 'cargando' });
    void recargar();
  };

  if (confirmacion) {
    const c = confirmacion;
    return (
      <div ref={confirmacionRef} tabIndex={-1} role="status" className="rounded-3xl border border-emerald-500/30 bg-[#0A1929]/90 p-8 text-stone-200 shadow-2xl outline-none backdrop-blur-2xl sm:p-10">
        <div className="mb-6 flex items-center gap-4 border-b border-white/10 pb-6">
          <div className="flex h-14 w-14 flex-shrink-0 items-center justify-center rounded-2xl border border-emerald-500/20 bg-emerald-500/10 text-emerald-400">
            <CheckCircle2 className="h-8 w-8" aria-hidden="true" />
          </div>
          <div>
            <span className="text-xs font-bold uppercase tracking-wider text-emerald-400">{c.repetida ? 'Ya teníamos tu solicitud' : 'Solicitud recibida'}</span>
            <h2 className="text-2xl font-extrabold text-white">
              Código de seguimiento: <span className="text-gold">{c.codigo}</span>
            </h2>
          </div>
        </div>

        <div className="space-y-4 text-sm">
          <div className="rounded-2xl border border-gold/30 bg-gold/10 p-4">
            <span className="text-xs font-semibold uppercase text-gold">Curso de interés</span>
            <p className="mt-1 text-base font-bold text-white">{c.curso}</p>
          </div>
          <p className="text-xs leading-relaxed text-stone-300">
            {c.repetida ? 'Ya tenías una solicitud abierta para este curso; no creamos otra. ' : ''}
            El equipo académico de Horus Group SRL revisará tu solicitud y se comunicará contigo por WhatsApp o al correo <strong className="text-white">{c.email}</strong> con los detalles de fechas, requisitos y pagos.
          </p>
          <div className="flex items-start gap-3 rounded-xl border border-white/10 bg-white/5 p-4 text-xs text-stone-300">
            <ShieldAlert className="mt-0.5 h-5 w-5 flex-shrink-0 text-gold" aria-hidden="true" />
            <span>Esta solicitud es informativa: no es una matrícula ni reserva un cupo. La matrícula se confirma con el equipo académico.</span>
          </div>
        </div>

        <div className="mt-8 flex flex-wrap gap-4 border-t border-white/10 pt-6">
          <Link href="/educacion/cursos" className="rounded-full bg-gold px-6 py-2.5 text-xs font-bold text-ink-dark shadow-gold hover:brightness-110 focus-visible:ring-2 focus-visible:ring-white/60 focus-visible:outline-none">
            Volver a cursos
          </Link>
          <a
            href={`https://wa.me/${CONTACT_INFO.phoneClean}?text=${encodeURIComponent(`Hola, acabo de enviar mi solicitud de matrícula con código ${c.codigo} para el curso ${c.curso}.`)}`}
            target="_blank"
            rel="noopener noreferrer"
            className="rounded-full border border-white/20 bg-white/10 px-6 py-2.5 text-xs font-semibold text-white hover:bg-white/20 focus-visible:ring-2 focus-visible:ring-gold/60 focus-visible:outline-none"
          >
            Escribir por WhatsApp
          </a>
          <button type="button" onClick={nueva} className="rounded-full border border-white/20 px-6 py-2.5 text-xs font-semibold text-white hover:bg-white/10 focus-visible:ring-2 focus-visible:ring-gold/60 focus-visible:outline-none">
            Otra solicitud
          </button>
        </div>
      </div>
    );
  }

  const listo = formulario.estado === 'listo';
  const cursos = formulario.estado === 'listo' ? formulario.cursos : [];
  const elegido = cursos.find((c) => c.valor === cursoElegido);
  const err = (c: Campo) => errors[c]?.message as string | undefined;
  const ariaCampo = (c: Campo) => ({ 'aria-invalid': err(c) ? true : undefined, 'aria-describedby': err(c) ? `${c}-error` : undefined });
  const mensajeError = (c: Campo) => (err(c) ? <p id={`${c}-error`} className="mt-1 text-xs text-red-400">{err(c)}</p> : null);

  return (
    <div className="rounded-3xl border border-white/10 bg-[#0A1929]/80 p-6 shadow-2xl backdrop-blur-2xl sm:p-10">
      <header className="mb-8">
        <Link href="/educacion/cursos" className="mb-4 inline-flex items-center gap-2 text-xs font-semibold text-gold hover:underline">
          <ArrowLeft className="h-4 w-4" aria-hidden="true" />
          <span>Volver al catálogo de cursos</span>
        </Link>
        <span className="block text-xs font-bold uppercase tracking-widest text-gold">Pre-inscripción</span>
        <h1 className="mt-1 text-2xl font-extrabold text-white sm:text-3xl">Solicita tu matrícula</h1>
        <p className="mt-2 text-xs text-stone-300 sm:text-sm">Completa tus datos y nuestro equipo académico se comunicará contigo para orientarte y confirmar los requisitos.</p>
      </header>

      {formulario.estado === 'deshabilitado' && (
        <div role="note" className="mb-6 flex items-start gap-3 rounded-2xl border border-gold/30 bg-gold/10 p-4 text-sm text-stone-200">
          <Info className="mt-0.5 h-5 w-5 flex-shrink-0 text-gold" aria-hidden="true" />
          <p>
            Por ahora no hay cursos abiertos para solicitar en línea. Escríbenos por{' '}
            <a href={CONTACT_INFO.socials.whatsapp} className="font-semibold text-gold underline" target="_blank" rel="noopener noreferrer">WhatsApp</a>
            {' '}o al correo {CONTACT_INFO.email} y te informaremos de las próximas convocatorias.
          </p>
        </div>
      )}
      {formulario.estado === 'error' && (
        <div role="alert" className="mb-6 flex flex-wrap items-center gap-3 rounded-2xl border border-red-400/30 bg-red-500/10 p-4 text-sm text-stone-200">
          <AlertCircle className="h-5 w-5 flex-shrink-0 text-red-400" aria-hidden="true" />
          <p className="flex-1">No pudimos cargar el formulario. Inténtalo de nuevo o escríbenos por <a href={CONTACT_INFO.socials.whatsapp} className="font-semibold text-gold underline" target="_blank" rel="noopener noreferrer">WhatsApp</a>.</p>
          <button type="button" onClick={() => { setFormulario({ estado: 'cargando' }); void recargar(); }} className="rounded-full border border-white/20 px-4 py-1.5 text-xs font-semibold text-white hover:bg-white/10">Reintentar</button>
        </div>
      )}

      <form onSubmit={(e) => void handleSubmit(onSubmit)(e)} noValidate aria-busy={isSubmitting} className="space-y-6">
        {/* Señuelo anti-bots: invisible y fuera del orden de tabulación. */}
        <div aria-hidden="true" className="absolute -left-[9999px] h-0 w-0 overflow-hidden">
          <label>No rellenar<input type="text" tabIndex={-1} autoComplete="off" value={trampa} onChange={(e) => setTrampa(e.target.value)} /></label>
        </div>

        {aviso && <div ref={avisoRef} tabIndex={-1} role="alert" className="rounded-2xl border border-red-400/30 bg-red-500/10 p-4 text-sm text-stone-100 outline-none">{aviso}</div>}

        <div>
          <label htmlFor="curso" className="mb-1.5 block text-xs font-semibold text-stone-300">Curso de interés *</label>
          <select id="curso" disabled={!listo} {...register('curso')} {...ariaCampo('curso')} className={`${input} disabled:opacity-50`}>
            <option value="">{formulario.estado === 'cargando' ? 'Cargando cursos…' : 'Selecciona un curso de la lista'}</option>
            {cursos.map((c) => (
              <option key={c.valor} value={c.valor}>
                {c.titulo} · {c.modalidad.toUpperCase()}
              </option>
            ))}
          </select>
          {mensajeError('curso')}
          {elegido && (
            <div className="mt-3 rounded-2xl border border-white/10 bg-white/5 p-4 text-xs text-stone-300">
              <p className="leading-relaxed">{elegido.resumen}</p>
              <dl className="mt-3 grid gap-2 sm:grid-cols-3">
                <div><dt className="font-semibold text-white">Modalidad</dt><dd className="capitalize">{elegido.modalidad}</dd></div>
                {elegido.horario && <div><dt className="font-semibold text-white">Horario</dt><dd>{elegido.horario}</dd></div>}
                <div><dt className="font-semibold text-white">{elegido.inscripcion ? 'Inscripción' : 'Disponible hasta'}</dt><dd>{elegido.inscripcion ?? fechaLarga(elegido.vigente_hasta)}</dd></div>
              </dl>
            </div>
          )}
        </div>

        <div className="grid gap-4 sm:grid-cols-2">
          <div>
            <label htmlFor="nombres" className="mb-1.5 block text-xs font-semibold text-stone-300">Nombres del participante *</label>
            <input id="nombres" type="text" autoComplete="given-name" maxLength={120} {...register('nombres')} {...ariaCampo('nombres')} className={input} />
            {mensajeError('nombres')}
          </div>
          <div>
            <label htmlFor="apellidos" className="mb-1.5 block text-xs font-semibold text-stone-300">Apellidos del participante *</label>
            <input id="apellidos" type="text" autoComplete="family-name" maxLength={120} {...register('apellidos')} {...ariaCampo('apellidos')} className={input} />
            {mensajeError('apellidos')}
          </div>
          <div>
            <label htmlFor="email" className="mb-1.5 block text-xs font-semibold text-stone-300">Correo electrónico *</label>
            <input id="email" type="email" autoComplete="email" maxLength={254} placeholder="correo@ejemplo.com" {...register('email')} {...ariaCampo('email')} className={input} />
            {mensajeError('email')}
          </div>
          <div>
            <label htmlFor="telefono" className="mb-1.5 block text-xs font-semibold text-stone-300">Teléfono / WhatsApp *</label>
            <input id="telefono" type="tel" autoComplete="tel" maxLength={30} {...register('telefono')} {...ariaCampo('telefono')} className={input} />
            {mensajeError('telefono')}
          </div>
        </div>

        <div className="border-t border-white/5 pt-3">
          <label className="flex cursor-pointer items-center gap-2 text-xs text-stone-300">
            <input type="checkbox" {...register('es_menor')} className="h-4 w-4 rounded border-white/20 bg-[#071321] accent-[#d4af37]" />
            <span>El participante es menor de edad (indica los datos de su padre, madre o representante).</span>
          </label>
          {esMenor && (
            <div className="mt-4 grid gap-4 rounded-2xl border border-white/10 bg-white/5 p-4 sm:grid-cols-2">
              <div className="sm:col-span-2">
                <label htmlFor="representante_nombre" className="mb-1.5 block text-xs font-semibold text-stone-300">Nombre del padre, madre o representante *</label>
                <input id="representante_nombre" type="text" maxLength={120} {...register('representante_nombre')} {...ariaCampo('representante_nombre')} className={input} />
                {mensajeError('representante_nombre')}
              </div>
              <div>
                <label htmlFor="representante_email" className="mb-1.5 block text-xs font-semibold text-stone-300">Correo del representante *</label>
                <input id="representante_email" type="email" maxLength={254} {...register('representante_email')} {...ariaCampo('representante_email')} className={input} />
                {mensajeError('representante_email')}
              </div>
              <div>
                <label htmlFor="representante_telefono" className="mb-1.5 block text-xs font-semibold text-stone-300">Teléfono del representante *</label>
                <input id="representante_telefono" type="tel" maxLength={30} {...register('representante_telefono')} {...ariaCampo('representante_telefono')} className={input} />
                {mensajeError('representante_telefono')}
              </div>
            </div>
          )}
        </div>

        <div>
          <label htmlFor="mensaje" className="mb-1.5 block text-xs font-semibold text-stone-300">Consulta o comentario adicional (opcional)</label>
          <textarea id="mensaje" rows={3} maxLength={1000} placeholder="¿Tienes alguna duda sobre horarios, pagos o temarios?" {...register('mensaje')} {...ariaCampo('mensaje')} className={`${input} p-4`} />
          {mensajeError('mensaje')}
        </div>

        <div className="space-y-3 border-t border-white/10 pt-4">
          <label className="flex cursor-pointer items-start gap-2.5 text-xs text-stone-300">
            <input type="checkbox" {...register('consentimiento')} {...ariaCampo('consentimiento')} className="mt-0.5 h-4 w-4 flex-shrink-0 rounded border-white/20 bg-[#071321] accent-[#d4af37]" />
            <span>
              Autorizo que Horus Group SRL use estos datos para contactarme y atender mi solicitud, conforme a su{' '}
              <Link href="/legales/politicas-privacidad" target="_blank" rel="noopener noreferrer" className="font-semibold text-gold underline">Política de Privacidad</Link>.
            </span>
          </label>
          {mensajeError('consentimiento')}
          <div className="flex flex-wrap items-center justify-between gap-4 pt-2">
            <span className="text-xs text-stone-400">Esta solicitud no es una matrícula ni reserva cupo.</span>
            <button
              type="submit"
              disabled={isSubmitting || !listo}
              className="inline-flex items-center gap-2 rounded-full bg-gradient-to-r from-gold to-gold-dark px-8 py-3.5 text-sm font-bold text-ink-dark shadow-gold transition-all hover:brightness-110 focus-visible:ring-2 focus-visible:ring-white/60 focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-50"
            >
              {isSubmitting && <Loader2 className="h-4 w-4 animate-spin" aria-hidden="true" />}
              {isSubmitting ? 'Enviando…' : formulario.estado === 'cargando' ? 'Preparando formulario…' : !listo ? 'Envío en línea no disponible' : 'Enviar solicitud'}
            </button>
          </div>
        </div>
      </form>
    </div>
  );
}
