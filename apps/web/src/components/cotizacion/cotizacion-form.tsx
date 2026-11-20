'use client';

import { useCallback, useEffect, useRef, useState } from 'react';
import Link from 'next/link';
import { useSearchParams } from 'next/navigation';
import { useForm, useWatch, type FieldPath } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { AlertCircle, CheckCircle2, Info, Loader2, ShieldAlert } from 'lucide-react';
import { CONTACT_INFO } from '@/data/contactos';

/**
 * Solicitud de cotización de servicios tecnológicos. Es la NECESIDAD del cliente: no es una cotización emitida, no tiene
 * precio ni compromiso económico. Laravel valida, vuelve a resolver los servicios y registra en el módulo comercial; este
 * esquema solo adelanta los mismos controles. El código de seguimiento es el que devuelve el servidor.
 */

const SIN_ETIQUETAS = /^[^<>]*$/;
const sinEtiquetas = 'No uses los caracteres < o >.';

const esquema = z.object({
  servicios: z.array(z.string()).min(1, 'Elige al menos un servicio.'),
  nombre: z.string().trim().min(2, 'Ingresa tu nombre.').max(120).regex(SIN_ETIQUETAS, sinEtiquetas),
  empresa: z.string().trim().max(120, 'Máximo 120 caracteres.').regex(SIN_ETIQUETAS, sinEtiquetas).optional(),
  email: z.string().trim().email('Ingresa un correo electrónico válido.').max(254),
  telefono: z.string().trim().regex(/^([+0-9() .-]{5,30})?$/, 'Ingresa un teléfono válido (solo números, +, espacios o guiones).').optional(),
  ubicacion: z.string().trim().min(2, 'Indica la ciudad o ubicación del proyecto.').max(120).regex(SIN_ETIQUETAS, sinEtiquetas),
  necesidad: z.string().trim().min(10, 'Describe tu necesidad (mínimo 10 caracteres).').max(3000, 'Máximo 3000 caracteres.').regex(SIN_ETIQUETAS, sinEtiquetas),
  informacion: z.string().trim().max(1000, 'Máximo 1000 caracteres.').regex(SIN_ETIQUETAS, sinEtiquetas).optional(),
  consentimiento: z.boolean().refine((v) => v, 'Debes aceptar para que podamos atender tu solicitud.'),
});

type Datos = z.infer<typeof esquema>;
type Campo = FieldPath<Datos>;

const CAMPOS_CONOCIDOS = new Set<string>(['servicios', 'nombre', 'empresa', 'email', 'telefono', 'ubicacion', 'necesidad', 'informacion', 'consentimiento']);

interface Servicio {
  valor: string;
  titulo: string;
  resumen: string | null;
}

type Estado =
  | { estado: 'cargando' }
  | { estado: 'error' }
  | { estado: 'deshabilitado' }
  | { estado: 'listo'; marca: string; servicios: Servicio[]; max: number };

interface Confirmacion {
  codigo: string;
  recibida: string;
  repetida: boolean;
  servicios: string[];
  email: string;
}

const input =
  'w-full rounded-xl border border-white/10 bg-[#071321] px-4 py-3 text-sm text-white placeholder:text-stone-500 focus:border-gold focus:outline-none focus-visible:ring-2 focus-visible:ring-gold/40 aria-[invalid=true]:border-red-400';

async function pedirFormulario(): Promise<Estado> {
  try {
    const r = await fetch('/api/cotizaciones', { cache: 'no-store', signal: AbortSignal.timeout(12_000) });
    if (!r.ok) return { estado: 'error' };
    const f = await r.json();
    if (f?.habilitado === true && typeof f.marca === 'string' && Array.isArray(f.servicios) && f.servicios.length > 0) {
      return { estado: 'listo', marca: f.marca, servicios: f.servicios as Servicio[], max: Number(f.max_servicios) || 5 };
    }
    return { estado: 'deshabilitado' };
  } catch {
    return { estado: 'error' };
  }
}

function fechaHora(iso: string): string {
  return new Date(iso).toLocaleString('es-PE', { dateStyle: 'long', timeStyle: 'short' });
}

export function CotizacionForm() {
  const servicioParam = (useSearchParams().get('servicio') ?? '').trim().toLowerCase();
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
    formState: { errors, isSubmitting, isSubmitted },
  } = useForm<Datos>({
    resolver: zodResolver(esquema),
    defaultValues: { servicios: [], consentimiento: false },
  });

  const seleccion = useWatch({ control, name: 'servicios' });
  const recargar = useCallback(async () => setFormulario(await pedirFormulario()), []);

  useEffect(() => {
    let vigente = true;
    void pedirFormulario().then((e) => {
      if (vigente) setFormulario(e);
    });
    return () => {
      vigente = false;
    };
  }, []);

  // Con las opciones ya pintadas: conserva solo los servicios que siguen disponibles; si no queda ninguno,
  // preselecciona por ?servicio= (título o referencia).
  useEffect(() => {
    if (formulario.estado !== 'listo') return;
    const vigentes = getValues('servicios').filter((v) => formulario.servicios.some((s) => s.valor === v));
    if (vigentes.length > 0) {
      if (vigentes.length !== getValues('servicios').length) setValue('servicios', vigentes);
      return;
    }
    const previo = formulario.servicios.find((s) => servicioParam !== '' && (s.valor === servicioParam || s.titulo.toLowerCase() === servicioParam));
    setValue('servicios', previo ? [previo.valor] : []);
  }, [formulario, servicioParam, getValues, setValue]);

  const mostrarAviso = (texto: string) => {
    setAviso(texto);
    requestAnimationFrame(() => avisoRef.current?.focus());
  };

  const alternar = (valor: string, max: number) => {
    const actual = getValues('servicios');
    const siguiente = actual.includes(valor) ? actual.filter((v) => v !== valor) : actual.length < max ? [...actual, valor] : actual;
    setValue('servicios', siguiente, { shouldValidate: isSubmitted });
  };

  const onSubmit = async (d: Datos) => {
    if (enviando.current || formulario.estado !== 'listo') return;
    enviando.current = true;
    setAviso(null);
    const cuerpo = {
      servicios: d.servicios, nombre: d.nombre, empresa: d.empresa ?? '', email: d.email, telefono: d.telefono ?? '', ubicacion: d.ubicacion,
      necesidad: d.necesidad, informacion: d.informacion ?? '', consentimiento: d.consentimiento, marca: formulario.marca, sitio_web: trampa,
    };
    const titulos = d.servicios.map((v) => formulario.servicios.find((s) => s.valor === v)?.titulo ?? '').filter(Boolean);

    try {
      const r = await fetch('/api/cotizaciones', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify(cuerpo),
        cache: 'no-store',
        signal: AbortSignal.timeout(25_000),
      });
      const respuesta = await r.json().catch(() => null);

      if (r.ok && typeof respuesta?.codigo === 'string') {
        setConfirmacion({ codigo: respuesta.codigo, recibida: String(respuesta.recibida_at ?? ''), repetida: respuesta.repetida === true, servicios: titulos, email: d.email });
        requestAnimationFrame(() => confirmacionRef.current?.focus());
        return;
      }
      if (r.status === 422) {
        const porCampo: Record<string, string[]> = respuesta?.errors ?? {};
        let renovar = false;
        for (const [clave, mensajes] of Object.entries(porCampo)) {
          const campo = clave.split('.')[0];
          if (campo === 'marca' || campo === 'sitio_web' || campo === 'servicios') renovar = true;
          if (CAMPOS_CONOCIDOS.has(campo)) setError(campo as Campo, { type: 'server', message: mensajes[0] });
        }
        if (renovar) {
          await recargar();
          mostrarAviso(porCampo.servicios || Object.keys(porCampo).some((k) => k.startsWith('servicios'))
            ? 'Alguno de los servicios elegidos ya no está disponible. Revisa la lista y vuelve a elegir.'
            : 'El formulario caducó o se envió demasiado rápido. Tus datos siguen aquí: espera unos segundos y vuelve a enviar.');
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
    reset({ servicios: [], consentimiento: false });
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
          <div className="min-w-0">
            <span className="text-xs font-bold uppercase tracking-wider text-emerald-400">{c.repetida ? 'Ya teníamos tu solicitud' : 'Solicitud recibida'}</span>
            <h2 className="text-xl font-extrabold text-white sm:text-2xl">
              Código de seguimiento: <span className="block break-all text-base text-gold sm:text-lg">{c.codigo}</span>
            </h2>
          </div>
        </div>

        <div className="space-y-4 text-sm">
          <div className="rounded-2xl border border-gold/30 bg-gold/10 p-4">
            <span className="text-xs font-semibold uppercase text-gold">Servicios de interés</span>
            <p className="mt-1 text-base font-bold text-white">{c.servicios.join(' · ')}</p>
          </div>
          {c.recibida && <p className="text-xs text-stone-400">Recibida el {fechaHora(c.recibida)}.</p>}
          <p className="text-xs leading-relaxed text-stone-300">
            {c.repetida ? 'Ya tenías una solicitud abierta con estos mismos datos; no creamos otra. ' : ''}
            Nuestro equipo de servicios tecnológicos revisará tu necesidad y se comunicará contigo al correo <strong className="text-white">{c.email}</strong> o por teléfono para coordinar los detalles.
          </p>
          <div className="flex items-start gap-3 rounded-xl border border-white/10 bg-white/5 p-4 text-xs text-stone-300">
            <ShieldAlert className="mt-0.5 h-5 w-5 flex-shrink-0 text-gold" aria-hidden="true" />
            <span>Esta es una solicitud de cotización: todavía no es una cotización ni implica un precio o un compromiso. La cotización formal te la enviará el equipo después de revisar tu caso.</span>
          </div>
        </div>

        <div className="mt-8 flex flex-wrap gap-4 border-t border-white/10 pt-6">
          <Link href="/" className="rounded-full bg-gold px-6 py-2.5 text-xs font-bold text-ink-dark shadow-gold hover:brightness-110 focus-visible:ring-2 focus-visible:ring-white/60 focus-visible:outline-none">
            Volver al inicio
          </Link>
          <a
            href={`https://wa.me/${CONTACT_INFO.phoneClean}?text=${encodeURIComponent(`Hola, acabo de enviar una solicitud de cotización con código ${c.codigo}.`)}`}
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
  const servicios = formulario.estado === 'listo' ? formulario.servicios : [];
  const max = formulario.estado === 'listo' ? formulario.max : 5;
  const err = (c: Campo) => (errors as Record<string, { message?: string } | undefined>)[c]?.message;
  const ariaCampo = (c: Campo) => ({ 'aria-invalid': err(c) ? true : undefined, 'aria-describedby': err(c) ? `${c}-error` : undefined });
  const mensajeError = (c: Campo) => (err(c) ? <p id={`${c}-error`} className="mt-1 text-xs text-red-400">{err(c)}</p> : null);

  return (
    <div className="rounded-3xl border border-white/10 bg-[#0A1929]/80 p-6 shadow-2xl backdrop-blur-2xl sm:p-10">
      <header className="mb-8">
        <span className="block text-xs font-bold uppercase tracking-widest text-gold">Servicios tecnológicos</span>
        <h1 className="mt-1 text-2xl font-extrabold text-white sm:text-3xl">Solicita una cotización</h1>
        <p className="mt-2 text-xs text-stone-300 sm:text-sm">Cuéntanos qué necesitas y nuestro equipo te contactará para preparar una propuesta. Este formulario no genera precios automáticos.</p>
      </header>

      {formulario.estado === 'deshabilitado' && (
        <div role="note" className="mb-6 flex items-start gap-3 rounded-2xl border border-gold/30 bg-gold/10 p-4 text-sm text-stone-200">
          <Info className="mt-0.5 h-5 w-5 flex-shrink-0 text-gold" aria-hidden="true" />
          <p>
            Por ahora no recibimos solicitudes de cotización en línea. Escríbenos por{' '}
            <a href={CONTACT_INFO.socials.whatsapp} className="font-semibold text-gold underline" target="_blank" rel="noopener noreferrer">WhatsApp</a>
            {' '}o al correo {CONTACT_INFO.email} y te atenderemos.
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

        <fieldset aria-describedby={err('servicios') ? 'servicios-error' : undefined}>
          <legend className="mb-2 text-xs font-semibold text-stone-300">Servicios de interés * <span className="font-normal text-stone-400">(hasta {max})</span></legend>
          {formulario.estado === 'cargando' && <p className="text-xs text-stone-400">Cargando servicios…</p>}
          <div className="grid gap-3 sm:grid-cols-2">
            {servicios.map((s) => {
              const marcado = seleccion.includes(s.valor);
              return (
                <label key={s.valor} className="flex cursor-pointer items-start gap-3 rounded-2xl border border-white/10 bg-[#071321] p-4 transition-colors hover:border-white/30 has-[:checked]:border-gold has-[:checked]:bg-gold/10 has-[:focus-visible]:ring-2 has-[:focus-visible]:ring-gold">
                  <input type="checkbox" checked={marcado} disabled={!marcado && seleccion.length >= max} onChange={() => alternar(s.valor, max)} className="mt-0.5 h-4 w-4 flex-shrink-0 rounded border-white/20 bg-[#071321] accent-[#d4af37]" />
                  <span className="min-w-0">
                    <span className="block text-sm font-semibold text-white">{s.titulo}</span>
                    {s.resumen && <span className="mt-1 block text-xs leading-relaxed text-stone-400">{s.resumen}</span>}
                  </span>
                </label>
              );
            })}
          </div>
          {mensajeError('servicios')}
        </fieldset>

        <div className="grid gap-4 sm:grid-cols-2">
          <div>
            <label htmlFor="nombre" className="mb-1.5 block text-xs font-semibold text-stone-300">Tu nombre *</label>
            <input id="nombre" type="text" autoComplete="name" maxLength={120} {...register('nombre')} {...ariaCampo('nombre')} className={input} />
            {mensajeError('nombre')}
          </div>
          <div>
            <label htmlFor="empresa" className="mb-1.5 block text-xs font-semibold text-stone-300">Empresa u organización (opcional)</label>
            <input id="empresa" type="text" autoComplete="organization" maxLength={120} {...register('empresa')} {...ariaCampo('empresa')} className={input} />
            {mensajeError('empresa')}
          </div>
          <div>
            <label htmlFor="email" className="mb-1.5 block text-xs font-semibold text-stone-300">Correo electrónico *</label>
            <input id="email" type="email" autoComplete="email" maxLength={254} placeholder="correo@ejemplo.com" {...register('email')} {...ariaCampo('email')} className={input} />
            {mensajeError('email')}
          </div>
          <div>
            <label htmlFor="telefono" className="mb-1.5 block text-xs font-semibold text-stone-300">Teléfono / WhatsApp (opcional)</label>
            <input id="telefono" type="tel" autoComplete="tel" maxLength={30} {...register('telefono')} {...ariaCampo('telefono')} className={input} />
            {mensajeError('telefono')}
          </div>
          <div className="sm:col-span-2">
            <label htmlFor="ubicacion" className="mb-1.5 block text-xs font-semibold text-stone-300">Ciudad o ubicación del proyecto *</label>
            <input id="ubicacion" type="text" autoComplete="address-level2" maxLength={120} placeholder="Ej. Cajamarca, Jr. José Gálvez" {...register('ubicacion')} {...ariaCampo('ubicacion')} className={input} />
            {mensajeError('ubicacion')}
          </div>
        </div>

        <div>
          <label htmlFor="necesidad" className="mb-1.5 block text-xs font-semibold text-stone-300">Describe tu necesidad *</label>
          <textarea id="necesidad" rows={5} maxLength={3000} placeholder="¿Qué quieres instalar, mejorar o reparar? Cantidad de puntos, equipos, tamaño del lugar, plazos…" {...register('necesidad')} {...ariaCampo('necesidad')} className={`${input} p-4`} />
          {mensajeError('necesidad')}
        </div>
        <div>
          <label htmlFor="informacion" className="mb-1.5 block text-xs font-semibold text-stone-300">Información adicional (opcional)</label>
          <textarea id="informacion" rows={3} maxLength={1000} placeholder="Horarios de visita, restricciones del lugar, presupuesto referencial si deseas compartirlo…" {...register('informacion')} {...ariaCampo('informacion')} className={`${input} p-4`} />
          {mensajeError('informacion')}
        </div>

        <div className="space-y-3 border-t border-white/10 pt-4">
          <label className="flex cursor-pointer items-start gap-2.5 text-xs text-stone-300">
            <input type="checkbox" {...register('consentimiento')} {...ariaCampo('consentimiento')} className="mt-0.5 h-4 w-4 flex-shrink-0 rounded border-white/20 bg-[#071321] accent-[#d4af37]" />
            <span>
              Autorizo que Horus Group SRL use estos datos para contactarme y preparar una propuesta, conforme a su{' '}
              <Link href="/legales/politicas-privacidad" target="_blank" rel="noopener noreferrer" className="font-semibold text-gold underline">Política de Privacidad</Link>.
            </span>
          </label>
          {mensajeError('consentimiento')}
          <div className="flex flex-wrap items-center justify-between gap-4 pt-2">
            <span className="text-xs text-stone-400">No pedimos datos de pago ni generamos precios automáticos.</span>
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
