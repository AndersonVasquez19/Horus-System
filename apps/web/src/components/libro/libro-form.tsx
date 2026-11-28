'use client';

import { useCallback, useEffect, useRef, useState, type ReactNode } from 'react';
import { useForm, useWatch, type FieldPath } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { FileText, AlertCircle, CheckCircle2, Printer, Info, Loader2 } from 'lucide-react';
import { CONTACT_INFO } from '@/data/contactos';

/**
 * Libro de Reclamaciones virtual. Las reglas AUTORITATIVAS (formato del documento, plazos, límites, numeración,
 * registro y auditoría) viven en Laravel; este esquema solo adelanta los mismos controles para avisar antes de enviar.
 * El número de registro y la fecha límite son los que devuelve el servidor: nunca se calculan ni inventan aquí.
 */

const SIN_ETIQUETAS = /^[^<>]*$/;
const SOLO_NOMBRE = /^[\p{L}\s'\-.]+$/u;
const CON_LETRAS = /\p{L}/u;
const TELEFONO = /^[+0-9() .-]{5,30}$/;
const MONTO = /^\d{1,10}([.,]\d{1,2})?$/;

function telefonoValido(valor: string): boolean {
  if (!TELEFONO.test(valor)) return false;
  const digitos = valor.replace(/\D+/g, '');
  return digitos.length >= 7 && digitos.length <= 15;
}

function rucPeruanoValido(ruc: string): boolean {
  if (!/^(10|15|16|17|20)\d{9}$/.test(ruc)) return false;
  const factores = [5, 4, 3, 2, 7, 6, 5, 4, 3, 2];
  let suma = 0;
  for (let i = 0; i < 10; i++) suma += Number(ruc[i]) * factores[i];
  const complemento = 11 - (suma % 11);
  const verificador = complemento === 10 ? 0 : complemento === 11 ? 1 : complemento;
  return verificador === Number(ruc[10]);
}

const FORMATO_DOCUMENTO: Record<string, [RegExp, string]> = {
  dni: [/^\d{8}$/, 'El DNI debe tener 8 dígitos válidos.'],
  ruc: [/^\d{11}$/, 'El RUC debe tener 11 dígitos válidos de SUNAT.'],
  ce: [/^[A-Za-z0-9]{9,12}$/, 'El carné de extranjería tiene entre 9 y 12 caracteres.'],
  pasaporte: [/^[A-Za-z0-9]{6,12}$/, 'El pasaporte tiene entre 6 y 12 caracteres.'],
};

const texto = (min: number, max: number, vacio: string) =>
  z.string().trim().min(min, vacio).max(max, `Máximo ${max} caracteres.`).regex(SIN_ETIQUETAS, 'No uses los caracteres < o >.');

const textoNombre = (vacio: string) =>
  texto(2, 120, vacio)
    .regex(SOLO_NOMBRE, 'Usa solo letras, espacios, guiones o apóstrofes.')
    .regex(CON_LETRAS, 'Ingresa un nombre válido.');

const esquema = z
  .object({
    tipo: z.enum(['reclamo', 'queja']),
    tipo_documento: z.enum(['dni', 'ce', 'pasaporte', 'ruc']),
    numero_documento: z.string().trim().min(1, 'Ingresa tu número de documento.').max(20),
    nombres: textoNombre('Ingresa tus nombres.'),
    apellidos: textoNombre('Ingresa tus apellidos.'),
    telefono: z.string().trim().max(30, 'Máximo 30 caracteres.').refine(telefonoValido, 'Ingresa un teléfono válido de entre 7 y 15 dígitos.'),
    email: z.string().trim().email('Ingresa un correo electrónico válido.').max(254),
    domicilio: texto(5, 255, 'Ingresa tu domicilio o dirección.'),
    es_menor: z.boolean(),
    representante_nombre: z.string().trim().max(120).regex(SIN_ETIQUETAS, 'No uses los caracteres < o >.').optional(),
    representante_domicilio: z.string().trim().max(255).regex(SIN_ETIQUETAS, 'No uses los caracteres < o >.').optional(),
    representante_telefono: z.string().trim().max(30, 'Máximo 30 caracteres.').optional(),
    representante_email: z.string().trim().optional(),
    bien_tipo: z.enum(['producto', 'servicio']).optional(),
    bien_descripcion: z.string().trim().max(500, 'Máximo 500 caracteres.').regex(SIN_ETIQUETAS, 'No uses los caracteres < o >.').optional(),
    monto_bien: z.string().trim().optional(),
    monto_no_aplica: z.boolean(),
    detalle: texto(10, 3000, 'Explica con detalle lo ocurrido (mínimo 10 caracteres).').regex(CON_LETRAS, 'Incluye una descripción legible.'),
    pedido: texto(5, 1500, 'Indica qué solución solicitas (mínimo 5 caracteres).').regex(CON_LETRAS, 'Incluye un pedido legible.'),
    acepta_privacidad: z.boolean().refine((v) => v, 'Debes aceptar la declaración para enviar la hoja.'),
  })
  .superRefine((d, ctx) => {
    const docLimpio = d.numero_documento.replace(/\s+/g, '');
    const [patron, mensaje] = FORMATO_DOCUMENTO[d.tipo_documento];
    const docValido =
      d.tipo_documento === 'dni'
        ? patron.test(docLimpio) && docLimpio !== '00000000'
        : d.tipo_documento === 'ruc'
          ? rucPeruanoValido(docLimpio)
          : patron.test(docLimpio);
    if (!docValido) ctx.addIssue({ code: 'custom', path: ['numero_documento'], message: mensaje });
    if (d.es_menor) {
      const repNombre = d.representante_nombre ?? '';
      if (repNombre.length < 2 || !SOLO_NOMBRE.test(repNombre) || !CON_LETRAS.test(repNombre)) {
        ctx.addIssue({ code: 'custom', path: ['representante_nombre'], message: 'Ingresa el nombre válido del padre, madre o representante.' });
      }
      if (!d.representante_domicilio) ctx.addIssue({ code: 'custom', path: ['representante_domicilio'], message: 'Ingresa el domicilio del representante.' });
      if (!d.representante_telefono || !telefonoValido(d.representante_telefono)) {
        ctx.addIssue({ code: 'custom', path: ['representante_telefono'], message: 'Ingresa un teléfono válido del representante (7 a 15 dígitos).' });
      }
      if (!d.representante_email || !z.string().email().safeParse(d.representante_email).success) ctx.addIssue({ code: 'custom', path: ['representante_email'], message: 'Ingresa un correo válido del representante.' });
    }
    if (d.tipo === 'reclamo') {
      if (!d.bien_tipo) ctx.addIssue({ code: 'custom', path: ['bien_tipo'], message: 'Indica si es un producto o un servicio.' });
      const bienDesc = d.bien_descripcion ?? '';
      if (bienDesc.length < 5 || !CON_LETRAS.test(bienDesc)) {
        ctx.addIssue({ code: 'custom', path: ['bien_descripcion'], message: 'Describe el producto o servicio (mínimo 5 caracteres).' });
      }
      if (!d.monto_no_aplica) {
        const montoTexto = d.monto_bien ?? '';
        const montoNum = Number(montoTexto.replace(',', '.'));
        if (!MONTO.test(montoTexto) || !Number.isFinite(montoNum) || montoNum <= 0) {
          ctx.addIssue({ code: 'custom', path: ['monto_bien'], message: 'Indica un monto mayor a cero (hasta dos decimales) o marca «No aplica».' });
        }
      }
    }
  });

type Datos = z.infer<typeof esquema>;
type Campo = FieldPath<Datos>;

const CAMPOS_CONOCIDOS = new Set<string>([
  'tipo', 'tipo_documento', 'numero_documento', 'nombres', 'apellidos', 'telefono', 'email', 'domicilio', 'es_menor',
  'representante_nombre', 'representante_domicilio', 'representante_telefono', 'representante_email',
  'bien_tipo', 'bien_descripcion', 'monto_bien', 'monto_no_aplica', 'detalle', 'pedido', 'acepta_privacidad',
]);

type Credenciales =
  | { estado: 'cargando' }
  | { estado: 'error' }
  | { estado: 'deshabilitado' }
  | { estado: 'listo'; marca: string; token: string; correoActivo: boolean; plazo: number; honeypot: string };

interface Confirmacion {
  numero: string;
  tipo: 'reclamo' | 'queja';
  fechaLimite: string;
  repetida: boolean;
  correoActivo: boolean;
  plazo: number;
  datos: Pick<Datos, 'nombres' | 'apellidos' | 'tipo_documento' | 'numero_documento' | 'email' | 'telefono'>;
}

const input =
  'w-full rounded-xl border border-white/10 bg-[#071321] px-4 py-2.5 text-sm text-white placeholder:text-stone-500 focus:border-gold focus:outline-none focus-visible:ring-2 focus-visible:ring-gold/40 aria-[invalid=true]:border-red-400';
const area = 'w-full rounded-xl border border-white/10 bg-[#071321] p-4 text-sm text-white placeholder:text-stone-500 focus:border-gold focus:outline-none focus-visible:ring-2 focus-visible:ring-gold/40 aria-[invalid=true]:border-red-400';

function fechaLarga(iso: string): string {
  const [a, m, d] = iso.split('-').map(Number);
  return new Date(a, m - 1, d).toLocaleDateString('es-PE', { year: 'numeric', month: 'long', day: 'numeric' });
}

function Seccion({ numero, titulo, children }: { numero: number; titulo: string; children: ReactNode }) {
  return (
    <fieldset className="min-w-0">
      <legend className="mb-4 flex items-center gap-2 text-base font-bold uppercase tracking-wider text-gold">
        <span className="h-2 w-2 rounded-full bg-gold" aria-hidden="true" />
        {numero}. {titulo}
      </legend>
      {children}
    </fieldset>
  );
}

/** Pide al BFF el estado del libro y las credenciales de un solo uso (marca firmada y token de envío). */
async function pedirCredenciales(): Promise<Credenciales> {
  try {
    const r = await fetch('/api/reclamaciones', { cache: 'no-store', signal: AbortSignal.timeout(12_000) });
    if (!r.ok) return { estado: 'error' };
    const f = await r.json();
    if (f?.habilitado === true && typeof f.marca === 'string' && typeof f.token_envio === 'string') {
      return { estado: 'listo', marca: f.marca, token: f.token_envio, correoActivo: f.correo_activo === true, plazo: Number(f.plazo_dias_habiles) || 15, honeypot: String(f.honeypot ?? 'sitio_web') };
    }
    return { estado: 'deshabilitado' };
  } catch {
    return { estado: 'error' };
  }
}

export function LibroForm() {
  const [credenciales, setCredenciales] = useState<Credenciales>({ estado: 'cargando' });
  const [confirmacion, setConfirmacion] = useState<Confirmacion | null>(null);
  const [aviso, setAviso] = useState<{ tipo: 'error' | 'info'; texto: string } | null>(null);
  const [trampa, setTrampa] = useState('');
  const enviando = useRef(false);
  const avisoRef = useRef<HTMLDivElement>(null);
  const confirmacionRef = useRef<HTMLDivElement>(null);

  const {
    register,
    handleSubmit,
    control,
    setValue,
    setError,
    reset,
    formState: { errors, isSubmitting },
  } = useForm<Datos>({
    resolver: zodResolver(esquema),
    defaultValues: { tipo: 'reclamo', tipo_documento: 'dni', es_menor: false, monto_no_aplica: false, bien_tipo: 'servicio', acepta_privacidad: false },
  });

  const tipo = useWatch({ control, name: 'tipo' });
  const tipoDocumento = useWatch({ control, name: 'tipo_documento' });
  const esMenor = useWatch({ control, name: 'es_menor' });
  const montoNoAplica = useWatch({ control, name: 'monto_no_aplica' });
  const detalleValor = useWatch({ control, name: 'detalle' }) ?? '';
  const pedidoValor = useWatch({ control, name: 'pedido' }) ?? '';
  const esReclamo = tipo === 'reclamo';
  const nombreTipo = esReclamo ? 'reclamo' : 'queja';
  const maxDocumento = tipoDocumento === 'dni' ? 8 : tipoDocumento === 'ruc' ? 11 : 12;
  const modoDocumento = tipoDocumento === 'dni' || tipoDocumento === 'ruc' ? 'numeric' : 'text';

  const cargarCredenciales = useCallback(async () => setCredenciales(await pedirCredenciales()), []);

  useEffect(() => {
    let vigente = true;
    void pedirCredenciales().then((c) => {
      if (vigente) setCredenciales(c);
    });
    return () => {
      vigente = false;
    };
  }, []);

  const mostrarAviso = (tipoAviso: 'error' | 'info', texto: string) => {
    setAviso({ tipo: tipoAviso, texto });
    requestAnimationFrame(() => avisoRef.current?.focus());
  };

  const onSubmit = async (d: Datos) => {
    if (enviando.current || credenciales.estado !== 'listo') return;
    enviando.current = true;
    setAviso(null);
    const cuerpo: Record<string, unknown> = {
      tipo: d.tipo, tipo_documento: d.tipo_documento, numero_documento: d.numero_documento.replace(/\s+/g, ''),
      nombres: d.nombres, apellidos: d.apellidos, telefono: d.telefono, email: d.email, domicilio: d.domicilio,
      es_menor: d.es_menor, detalle: d.detalle, pedido: d.pedido, acepta_privacidad: d.acepta_privacidad,
      marca: credenciales.marca, token_envio: credenciales.token, [credenciales.honeypot]: trampa,
    };
    if (d.es_menor) {
      Object.assign(cuerpo, {
        representante_nombre: d.representante_nombre, representante_domicilio: d.representante_domicilio,
        representante_telefono: d.representante_telefono, representante_email: d.representante_email,
      });
    }
    if (d.tipo === 'reclamo') {
      Object.assign(cuerpo, { bien_tipo: d.bien_tipo, bien_descripcion: d.bien_descripcion, monto_no_aplica: d.monto_no_aplica });
      if (!d.monto_no_aplica) cuerpo.monto_bien = (d.monto_bien ?? '').replace(',', '.');
    }

    try {
      const r = await fetch('/api/reclamaciones', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify(cuerpo),
        cache: 'no-store',
        signal: AbortSignal.timeout(25_000),
      });
      const cuerpoRespuesta = await r.json().catch(() => null);

      if (r.ok && typeof cuerpoRespuesta?.numero === 'string') {
        setConfirmacion({
          numero: cuerpoRespuesta.numero, tipo: cuerpoRespuesta.tipo, fechaLimite: cuerpoRespuesta.fecha_limite,
          repetida: cuerpoRespuesta.repetida === true, correoActivo: cuerpoRespuesta.correo_activo === true, plazo: credenciales.plazo,
          datos: { nombres: d.nombres, apellidos: d.apellidos, tipo_documento: d.tipo_documento, numero_documento: d.numero_documento, email: d.email, telefono: d.telefono },
        });
        requestAnimationFrame(() => confirmacionRef.current?.focus());
        return;
      }
      if (r.status === 422) {
        const porCampo: Record<string, string[]> = cuerpoRespuesta?.errors ?? {};
        let renovar = false;
        for (const [campo, mensajes] of Object.entries(porCampo)) {
          if (campo === 'marca' || campo === credenciales.honeypot) renovar = true;
          else if (CAMPOS_CONOCIDOS.has(campo)) setError(campo as Campo, { type: 'server', message: mensajes[0] });
        }
        if (renovar) {
          await cargarCredenciales();
          mostrarAviso('error', 'El formulario caducó o se envió demasiado rápido. Tus datos siguen aquí: espera unos segundos y vuelve a enviar.');
        } else {
          mostrarAviso('error', 'Revisa los campos marcados. Tus datos no se han perdido.');
        }
        return;
      }
      if (r.status === 429) {
        const minutos = Math.max(1, Math.ceil(Number(r.headers.get('retry-after') ?? '600') / 60));
        mostrarAviso('error', `Se alcanzó el límite de envíos. Podrás volver a intentarlo en unos ${minutos} minuto(s). Tus datos siguen en el formulario.`);
        return;
      }
      mostrarAviso('error', 'No pudimos registrar tu hoja en este momento. Tus datos siguen aquí; puedes volver a intentarlo o escribirnos por WhatsApp.');
    } catch {
      // Sin respuesta (red o tiempo agotado): el mismo token hace que un reintento no cree una hoja duplicada.
      mostrarAviso('error', 'No pudimos conectar con el servidor. Tus datos siguen aquí; puedes reintentar sin riesgo de duplicar tu hoja.');
    } finally {
      enviando.current = false;
    }
  };

  const nueva = () => {
    setConfirmacion(null);
    setAviso(null);
    reset();
    setCredenciales({ estado: 'cargando' });
    void cargarCredenciales();
  };

  if (confirmacion) {
    const c = confirmacion;
    return (
      <div ref={confirmacionRef} tabIndex={-1} role="status" className="rounded-3xl border border-emerald-500/30 bg-[#0A1929]/90 p-6 text-stone-200 shadow-2xl outline-none backdrop-blur-2xl sm:p-10">
        <div className="mb-6 flex items-center gap-4 border-b border-white/10 pb-6">
          <div className="flex h-14 w-14 flex-shrink-0 items-center justify-center rounded-2xl border border-emerald-500/20 bg-emerald-500/10 text-emerald-400">
            <CheckCircle2 className="h-8 w-8" aria-hidden="true" />
          </div>
          <div>
            <span className="text-xs font-bold uppercase tracking-wider text-emerald-400">
              {c.repetida ? 'Esta hoja ya estaba registrada' : c.tipo === 'reclamo' ? 'Reclamo registrado' : 'Queja registrada'}
            </span>
            <h3 className="text-2xl font-extrabold text-white">
              Número de registro: <span className="text-gold">{c.numero}</span>
            </h3>
          </div>
        </div>

        <div className="grid gap-6 text-sm sm:grid-cols-2">
          <div className="space-y-2">
            <p className="text-xs font-semibold uppercase text-stone-400">Consumidor</p>
            <p className="font-bold text-white">{c.datos.nombres} {c.datos.apellidos}</p>
            <p className="text-stone-300">{c.datos.tipo_documento.toUpperCase()}: {c.datos.numero_documento}</p>
            <p className="text-stone-300">Correo: {c.datos.email}</p>
            <p className="text-stone-300">Teléfono: {c.datos.telefono}</p>
          </div>
          <div className="space-y-2">
            <p className="text-xs font-semibold uppercase text-stone-400">Registro</p>
            <p className="font-bold text-white">Tipo: {c.tipo === 'reclamo' ? 'Reclamo' : 'Queja'}</p>
            <p className="font-semibold text-gold">Fecha límite de respuesta: {fechaLarga(c.fechaLimite)} ({c.plazo} días hábiles)</p>
          </div>
        </div>

        <div className="mt-6 space-y-2 rounded-2xl border border-white/10 bg-white/5 p-4 text-xs leading-relaxed text-stone-300">
          <p className="font-semibold text-white">¿Qué sucede ahora?</p>
          <p>Conserva este número para cualquier consulta sobre tu registro. Horus Group SRL responderá por escrito al correo que indicaste.</p>
          <p>{c.correoActivo ? 'Te enviaremos una copia de tu hoja al correo consignado. Si no la ves, revisa la carpeta de correo no deseado.' : 'Por ahora no enviamos la copia automática por correo: imprime esta hoja o guarda el número de registro.'}</p>
        </div>

        <div className="mt-8 flex flex-wrap items-center justify-between gap-4 border-t border-white/10 pt-6">
          <button type="button" onClick={() => window.print()} className="inline-flex items-center gap-2 rounded-full border border-white/20 bg-white/10 px-6 py-2.5 text-xs font-semibold text-white transition-all hover:bg-white/20 focus-visible:ring-2 focus-visible:ring-gold/60 focus-visible:outline-none">
            <Printer className="h-4 w-4 text-gold" aria-hidden="true" />
            <span>Imprimir hoja</span>
          </button>
          <button type="button" onClick={nueva} className="rounded-full bg-gold px-6 py-2.5 text-xs font-bold text-ink-dark shadow-gold hover:brightness-110 focus-visible:ring-2 focus-visible:ring-white/60 focus-visible:outline-none">
            Registrar otra hoja
          </button>
        </div>
      </div>
    );
  }

  const listo = credenciales.estado === 'listo';
  const err = (c: Campo) => errors[c]?.message as string | undefined;
  const ariaCampo = (c: Campo) => ({ 'aria-invalid': err(c) ? true : undefined, 'aria-describedby': err(c) ? `${c}-error` : undefined });
  const mensajeError = (c: Campo) => (err(c) ? <p id={`${c}-error`} className="mt-1 text-xs text-red-400">{err(c)}</p> : null);

  return (
    <div className="space-y-10">
      <fieldset className="grid gap-4 sm:grid-cols-2">
        <legend className="sr-only">Tipo de registro</legend>
        {(
          [
            { valor: 'reclamo', titulo: 'RECLAMO', Icono: FileText, texto: <>Disconformidad relacionada con los <strong>productos o servicios</strong> adquiridos. Implica una insatisfacción directa con lo recibido o contratado.</> },
            { valor: 'queja', titulo: 'QUEJA', Icono: AlertCircle, texto: <>Malestar o descontento respecto a la <strong>atención al público</strong> recibida, sin relación directa con el producto o servicio contratado.</> },
          ] as const
        ).map(({ valor, titulo, Icono, texto: descripcion }) => (
          <label key={valor} className="cursor-pointer rounded-3xl border border-white/10 bg-[#0A1929]/70 p-6 shadow-xl transition-all hover:border-white/30 has-[:checked]:border-gold has-[:checked]:bg-gold/10 has-[:checked]:ring-2 has-[:checked]:ring-gold/30 has-[:focus-visible]:ring-2 has-[:focus-visible]:ring-gold">
            <input type="radio" value={valor} {...register('tipo')} className="sr-only" />
            <span className="mb-3 flex items-center gap-3">
              <span className="flex h-10 w-10 items-center justify-center rounded-2xl bg-gold/20 text-gold"><Icono className="h-5 w-5" aria-hidden="true" /></span>
              <span className="text-lg font-bold text-white">{titulo}</span>
            </span>
            <span className="block text-xs leading-relaxed text-stone-300">{descripcion}</span>
          </label>
        ))}
      </fieldset>

      {credenciales.estado === 'deshabilitado' && (
        <div role="note" className="flex items-start gap-3 rounded-2xl border border-gold/30 bg-gold/10 p-4 text-sm text-stone-200">
          <Info className="mt-0.5 h-5 w-5 flex-shrink-0 text-gold" aria-hidden="true" />
          <p>
            El registro en línea todavía no está habilitado. Puedes revisar el formulario; mientras tanto comunícate con nosotros por{' '}
            <a href={CONTACT_INFO.socials.whatsapp} className="font-semibold text-gold underline" target="_blank" rel="noopener noreferrer">WhatsApp</a>
            , al correo {CONTACT_INFO.email} o en {CONTACT_INFO.address}.
          </p>
        </div>
      )}
      {credenciales.estado === 'error' && (
        <div role="alert" className="flex flex-wrap items-center gap-3 rounded-2xl border border-red-400/30 bg-red-500/10 p-4 text-sm text-stone-200">
          <AlertCircle className="h-5 w-5 flex-shrink-0 text-red-400" aria-hidden="true" />
          <p className="flex-1">No pudimos preparar el formulario. Inténtalo de nuevo o escríbenos por <a href={CONTACT_INFO.socials.whatsapp} className="font-semibold text-gold underline" target="_blank" rel="noopener noreferrer">WhatsApp</a>.</p>
          <button type="button" onClick={() => { setCredenciales({ estado: 'cargando' }); void cargarCredenciales(); }} className="rounded-full border border-white/20 px-4 py-1.5 text-xs font-semibold text-white hover:bg-white/10">Reintentar</button>
        </div>
      )}

      <form onSubmit={(e) => void handleSubmit(onSubmit)(e)} noValidate aria-busy={isSubmitting} className="space-y-8 rounded-3xl border border-white/10 bg-[#0A1929]/80 p-6 shadow-2xl backdrop-blur-2xl sm:p-10">
        {/* Señuelo anti-bots: invisible y fuera del orden de tabulación; una persona nunca lo rellena. */}
        <div aria-hidden="true" className="absolute -left-[9999px] h-0 w-0 overflow-hidden">
          <label>No rellenar<input type="text" tabIndex={-1} autoComplete="off" value={trampa} onChange={(e) => setTrampa(e.target.value)} /></label>
        </div>

        {aviso && (
          <div ref={avisoRef} tabIndex={-1} role="alert" className="rounded-2xl border border-red-400/30 bg-red-500/10 p-4 text-sm text-stone-100 outline-none">{aviso.texto}</div>
        )}

        <Seccion numero={1} titulo="Identificación del consumidor reclamante">
          <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <div>
              <label htmlFor="tipo_documento" className="mb-1 block text-xs font-semibold text-stone-300">Tipo de documento *</label>
              <select id="tipo_documento" {...register('tipo_documento')} className={input}>
                <option value="dni">DNI</option>
                <option value="ce">Carné de extranjería (CE)</option>
                <option value="pasaporte">Pasaporte</option>
                <option value="ruc">RUC</option>
              </select>
            </div>
            <div>
              <label htmlFor="numero_documento" className="mb-1 block text-xs font-semibold text-stone-300">Número de documento *</label>
              <input id="numero_documento" type="text" inputMode={modoDocumento} autoComplete="off" maxLength={maxDocumento} {...register('numero_documento')} {...ariaCampo('numero_documento')} className={input} />
              {mensajeError('numero_documento')}
            </div>
            <div>
              <label htmlFor="telefono" className="mb-1 block text-xs font-semibold text-stone-300">Teléfono / celular *</label>
              <input id="telefono" type="tel" autoComplete="tel" maxLength={30} {...register('telefono')} {...ariaCampo('telefono')} className={input} />
              {mensajeError('telefono')}
            </div>
            <div>
              <label htmlFor="nombres" className="mb-1 block text-xs font-semibold text-stone-300">Nombres *</label>
              <input id="nombres" type="text" autoComplete="given-name" maxLength={120} {...register('nombres')} {...ariaCampo('nombres')} className={input} />
              {mensajeError('nombres')}
            </div>
            <div>
              <label htmlFor="apellidos" className="mb-1 block text-xs font-semibold text-stone-300">Apellidos *</label>
              <input id="apellidos" type="text" autoComplete="family-name" maxLength={120} {...register('apellidos')} {...ariaCampo('apellidos')} className={input} />
              {mensajeError('apellidos')}
            </div>
            <div>
              <label htmlFor="email" className="mb-1 block text-xs font-semibold text-stone-300">Correo electrónico *</label>
              <input id="email" type="email" autoComplete="email" maxLength={254} {...register('email')} {...ariaCampo('email')} className={input} />
              {mensajeError('email')}
            </div>
            <div className="sm:col-span-2 lg:col-span-3">
              <label htmlFor="domicilio" className="mb-1 block text-xs font-semibold text-stone-300">Domicilio / dirección *</label>
              <input id="domicilio" type="text" autoComplete="street-address" maxLength={255} placeholder="Calle, número, distrito, ciudad" {...register('domicilio')} {...ariaCampo('domicilio')} className={input} />
              {mensajeError('domicilio')}
            </div>
          </div>

          <div className="mt-4 border-t border-white/5 pt-3">
            <label className="flex cursor-pointer items-center gap-2 text-xs text-stone-300">
              <input type="checkbox" {...register('es_menor')} className="h-4 w-4 rounded border-white/20 bg-[#071321] accent-[#d4af37]" />
              <span>El reclamante es menor de edad (se consignan los datos del padre, madre o representante).</span>
            </label>
            {esMenor && (
              <div className="mt-4 grid gap-4 rounded-2xl border border-white/10 bg-white/5 p-4 sm:grid-cols-2">
                <div>
                  <label htmlFor="representante_nombre" className="mb-1 block text-xs font-semibold text-stone-300">Nombre del padre, madre o representante *</label>
                  <input id="representante_nombre" type="text" maxLength={120} {...register('representante_nombre')} {...ariaCampo('representante_nombre')} className={input} />
                  {mensajeError('representante_nombre')}
                </div>
                <div>
                  <label htmlFor="representante_telefono" className="mb-1 block text-xs font-semibold text-stone-300">Teléfono del representante *</label>
                  <input id="representante_telefono" type="tel" maxLength={30} {...register('representante_telefono')} {...ariaCampo('representante_telefono')} className={input} />
                  {mensajeError('representante_telefono')}
                </div>
                <div>
                  <label htmlFor="representante_email" className="mb-1 block text-xs font-semibold text-stone-300">Correo del representante *</label>
                  <input id="representante_email" type="email" maxLength={254} {...register('representante_email')} {...ariaCampo('representante_email')} className={input} />
                  {mensajeError('representante_email')}
                </div>
                <div>
                  <label htmlFor="representante_domicilio" className="mb-1 block text-xs font-semibold text-stone-300">Domicilio del representante *</label>
                  <input id="representante_domicilio" type="text" maxLength={255} {...register('representante_domicilio')} {...ariaCampo('representante_domicilio')} className={input} />
                  {mensajeError('representante_domicilio')}
                </div>
              </div>
            )}
          </div>
        </Seccion>

        {esReclamo && (
          <Seccion numero={2} titulo="Identificación del bien contratado">
            <div className="grid gap-4 sm:grid-cols-2">
              <div>
                <label htmlFor="bien_tipo" className="mb-1 block text-xs font-semibold text-stone-300">Tipo de bien *</label>
                <select id="bien_tipo" {...register('bien_tipo')} {...ariaCampo('bien_tipo')} className={input}>
                  <option value="servicio">Servicio (cursos, soporte, cableado, cámaras)</option>
                  <option value="producto">Producto (equipos, materiales)</option>
                </select>
                {mensajeError('bien_tipo')}
              </div>
              <div>
                <label htmlFor="monto_bien" className="mb-1 block text-xs font-semibold text-stone-300">Monto reclamado (S/) *</label>
                <input id="monto_bien" type="text" inputMode="decimal" autoComplete="off" maxLength={13} placeholder="Ej. 150.00" disabled={montoNoAplica} {...register('monto_bien')} {...ariaCampo('monto_bien')} className={`${input} disabled:opacity-40`} />
                <label className="mt-2 flex cursor-pointer items-center gap-2 text-xs text-stone-300">
                  <input type="checkbox" {...register('monto_no_aplica', { onChange: (e) => { if (e.target.checked) setValue('monto_bien', ''); } })} className="h-4 w-4 rounded border-white/20 bg-[#071321] accent-[#d4af37]" />
                  <span>No aplica</span>
                </label>
                {mensajeError('monto_bien')}
              </div>
              <div className="sm:col-span-2">
                <label htmlFor="bien_descripcion" className="mb-1 block text-xs font-semibold text-stone-300">Descripción del producto o servicio *</label>
                <input id="bien_descripcion" type="text" maxLength={500} placeholder="Ej. Curso de instalación de cámaras de seguridad" {...register('bien_descripcion')} {...ariaCampo('bien_descripcion')} className={input} />
                {mensajeError('bien_descripcion')}
              </div>
            </div>
          </Seccion>
        )}

        <Seccion numero={esReclamo ? 3 : 2} titulo="Detalle y pedido del consumidor">
          <div className="space-y-4">
            <div>
              <div className="mb-1 flex items-center justify-between gap-2">
                <label htmlFor="detalle" className="block text-xs font-semibold text-stone-300">Detalle del {nombreTipo} *</label>
                <span className="text-[11px] tabular-nums text-stone-400">{detalleValor.length} / 3000</span>
              </div>
              <textarea id="detalle" rows={5} maxLength={3000} placeholder={`Describe claramente los hechos que motivan tu ${nombreTipo}. Evita incluir datos sensibles (contraseñas, números de tarjeta, datos de salud).`} {...register('detalle')} {...ariaCampo('detalle')} className={area} />
              {mensajeError('detalle')}
            </div>
            <div>
              <div className="mb-1 flex items-center justify-between gap-2">
                <label htmlFor="pedido" className="block text-xs font-semibold text-stone-300">Pedido concreto *</label>
                <span className="text-[11px] tabular-nums text-stone-400">{pedidoValor.length} / 1500</span>
              </div>
              <textarea id="pedido" rows={3} maxLength={1500} placeholder="Indica qué solución o resultado esperas de parte de Horus Group SRL." {...register('pedido')} {...ariaCampo('pedido')} className={area} />
              {mensajeError('pedido')}
            </div>
          </div>
        </Seccion>

        <div className="space-y-4 border-t border-white/10 pt-4">
          <div className="rounded-2xl border border-white/10 bg-white/5 p-4 text-xs leading-relaxed text-stone-300">
            <p className="mb-1 font-semibold text-white">Tratamiento de tus datos personales</p>
            <p>
              Horus Group SRL usará los datos de esta hoja únicamente para registrar, atender, responder y conservar tu {nombreTipo} y para cumplir las obligaciones del Libro de Reclamaciones; no se usarán para publicidad. Más información en la{' '}
              <a href="/legales/politicas-privacidad" target="_blank" rel="noopener noreferrer" className="font-semibold text-gold underline">Política de Privacidad</a>.
            </p>
          </div>
          <label className="flex cursor-pointer items-start gap-2.5 text-xs text-stone-300">
            <input type="checkbox" {...register('acepta_privacidad')} {...ariaCampo('acepta_privacidad')} className="mt-0.5 h-4 w-4 flex-shrink-0 rounded border-white/20 bg-[#071321] accent-[#d4af37]" />
            <span>Declaro que la información proporcionada es verídica y acepto que Horus Group SRL procese mis datos personales conforme a su Política de Privacidad para gestionar este {nombreTipo}.</span>
          </label>
          {mensajeError('acepta_privacidad')}

          <div className="flex flex-wrap items-center justify-between gap-4 pt-4">
            <span className="text-xs text-stone-400">
              Plazo de respuesta: <strong>{listo ? credenciales.plazo : 15} días hábiles</strong>.
            </span>
            <button
              type="submit"
              disabled={isSubmitting || !listo}
              className="inline-flex items-center gap-2 rounded-full bg-gradient-to-r from-gold to-gold-dark px-8 py-3.5 text-sm font-bold text-ink-dark shadow-gold transition-all hover:brightness-110 focus-visible:ring-2 focus-visible:ring-white/60 focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-50"
            >
              {isSubmitting && <Loader2 className="h-4 w-4 animate-spin" aria-hidden="true" />}
              {isSubmitting ? 'Registrando hoja…' : credenciales.estado === 'cargando' ? 'Preparando formulario…' : credenciales.estado === 'deshabilitado' ? 'Envío en línea no disponible' : `Enviar ${nombreTipo}`}
            </button>
          </div>
        </div>
      </form>
    </div>
  );
}
