'use client';

import { Ban, Check, CirclePlus, Plus, Trash2, Undo2, X } from 'lucide-react';
import Link from 'next/link';
import { useRouter } from 'next/navigation';
import { useRef, useState } from 'react';
import { z } from 'zod';
import { usePuede } from '@/components/shared/sesion-panel-contexto';
import { useFormularioPanel } from '@/components/shared/use-formulario-panel';
import { useOperacionPanel } from '@/components/shared/use-operacion-panel';
import { Alerta } from '@/components/shared/ui/alerta';
import { useAvisos } from '@/components/shared/ui/avisos';
import { Boton } from '@/components/shared/ui/boton';
import { Campo, CampoSeleccion } from '@/components/shared/ui/campo';
import { Dialogo } from '@/components/shared/ui/dialogo';
import { Insignia } from '@/components/shared/ui/insignia';
import type { ObligacionDetalle, TransaccionPagoVista } from '@/features/academico/tipos';
import { fecha, moneda } from '@/lib/panel/formato';

const IMPORTE = /^(0|[1-9]\d{0,7})(\.\d{1,2})?$/;
const COLOR_COBRO = { pendiente: 'ambar', parcial: 'azul', pagada: 'verde', anulada: 'rojo' } as const;
const COLOR_TX = { pendiente: 'ambar', confirmada: 'verde', rechazada: 'rojo' } as const;
export const ETIQUETA_COBRO = { pendiente: 'Pendiente', parcial: 'Parcial', pagada: 'Pagada', anulada: 'Anulada' } as const;

const hoyLima = () => new Intl.DateTimeFormat('en-CA', { timeZone: 'America/Lima' }).format(new Date());
const clave = () => crypto.randomUUID();

// ---- Obligación desde la ficha de la matrícula ------------------------------------------------------------------------

const esquemaObligacion = z.object({
  concepto: z.string().trim().min(3, 'Indica el concepto.').max(120, 'Hasta 120 caracteres.'),
  plan: z.enum(['completo', 'fraccionado']),
  monto_total: z.string().regex(IMPORTE, 'Importe válido, hasta 2 decimales.').refine((v) => Number(v) > 0, 'Debe ser mayor que cero.'),
});

/** Crea la obligación de pago de una matrícula (completo o fraccionado). La suma de cuotas se valida aquí y en Laravel. */
export function BotonCrearObligacion({ matriculaId }: { matriculaId: number }) {
  const puede = usePuede('pagos.planificar');
  const [abierto, setAbierto] = useState(false);
  const [ocupado, setOcupado] = useState(false);
  if (!puede) return null;
  return (
    <>
      <Boton icono={<Plus className="h-4 w-4" aria-hidden="true" />} onClick={() => setAbierto(true)}>Crear obligación de pago</Boton>
      <Dialogo abierto={abierto} titulo="Obligación de pago" descripcion="Solo soles (PEN), monto fijo." onCerrar={() => setAbierto(false)} bloqueado={ocupado}>
        <FormularioObligacion matriculaId={matriculaId} onOcupado={setOcupado} onCerrar={() => setAbierto(false)} />
      </Dialogo>
    </>
  );
}

function FormularioObligacion({ matriculaId, onOcupado, onCerrar }: { matriculaId: number; onOcupado: (b: boolean) => void; onCerrar: () => void }) {
  const router = useRouter();
  const { mostrar } = useAvisos();
  const { ejecutar } = useOperacionPanel();
  const [cuotas, setCuotas] = useState<{ monto: string; vence: string }[]>([{ monto: '', vence: '' }, { monto: '', vence: '' }]);
  const [errorCuotas, setErrorCuotas] = useState<string | null>(null);
  const f = useFormularioPanel({
    esquema: esquemaObligacion,
    valores: { concepto: '', plan: 'completo' as 'completo' | 'fraccionado', monto_total: '' },
    onOcupado,
    enviar: async (d) => {
      setErrorCuotas(null);
      if (d.plan === 'fraccionado') {
        const suma = cuotas.reduce((t, c) => t + Math.round(Number(c.monto) * 100), 0);
        if (cuotas.some((c) => !IMPORTE.test(c.monto) || c.vence === '')) {
          setErrorCuotas('Completa el importe y el vencimiento de cada cuota.');
          return { ok: false as const, estado: 422, error: { message: 'Revisa las cuotas.', code: 'cuotas' } };
        }
        if (suma !== Math.round(Number(d.monto_total) * 100)) {
          setErrorCuotas('La suma de las cuotas debe igualar el monto total.');
          return { ok: false as const, estado: 422, error: { message: 'Revisa las cuotas.', code: 'cuotas' } };
        }
      }
      return ejecutar<{ message: string }>(`academico/matriculas/${matriculaId}/obligacion`, 'POST', { ...d, cuotas: d.plan === 'fraccionado' ? cuotas : undefined });
    },
    onExito: (r) => {
      onCerrar();
      mostrar('exito', r.message);
      router.refresh();
    },
  });
  const fraccionado = f.form.watch('plan') === 'fraccionado';
  const actualizar = (i: number, campo: 'monto' | 'vence', valor: string) => setCuotas((p) => p.map((c, k) => (k === i ? { ...c, [campo]: valor } : c)));

  return (
    <form onSubmit={f.enviar} noValidate className="space-y-4">
      {f.errorGeneral ? <Alerta tipo="error">{f.errorGeneral}</Alerta> : null}
      <Campo etiqueta="Concepto" {...f.registro('concepto')} error={f.error('concepto')} />
      <div className="grid gap-4 sm:grid-cols-2">
        <CampoSeleccion etiqueta="Plan" {...f.registro('plan')} error={f.error('plan')} opciones={{ completo: 'Pago completo', fraccionado: 'Fraccionado en cuotas' }} />
        <Campo etiqueta="Monto total (S/)" inputMode="decimal" {...f.registro('monto_total')} error={f.error('monto_total')} />
      </div>
      {fraccionado ? (
        <fieldset className="space-y-2">
          <legend className="text-sm font-medium text-panel-text">Cuotas</legend>
          {cuotas.map((c, i) => (
            <div key={i} className="grid grid-cols-[1fr_1fr_auto] items-end gap-2">
              <Campo etiqueta={`Cuota ${i + 1} (S/)`} inputMode="decimal" value={c.monto} onChange={(e) => actualizar(i, 'monto', e.target.value)} />
              <Campo etiqueta="Vence" type="date" value={c.vence} onChange={(e) => actualizar(i, 'vence', e.target.value)} />
              <Boton variante="fantasma" aria-label={`Quitar cuota ${i + 1}`} disabled={cuotas.length <= 2} onClick={() => setCuotas((p) => p.filter((_, k) => k !== i))} icono={<Trash2 className="h-4 w-4" aria-hidden="true" />} />
            </div>
          ))}
          {errorCuotas ? <p className="text-sm text-estado-error">{errorCuotas}</p> : null}
          <Boton variante="secundario" disabled={cuotas.length >= 24} onClick={() => setCuotas((p) => [...p, { monto: '', vence: '' }])} icono={<CirclePlus className="h-4 w-4" aria-hidden="true" />}>Añadir cuota</Boton>
        </fieldset>
      ) : null}
      <div className="flex flex-col-reverse gap-2 border-t border-panel-border pt-4 sm:flex-row sm:justify-end">
        <Boton variante="secundario" onClick={onCerrar} disabled={f.guardando}>Cancelar</Boton>
        <Boton type="submit" cargando={f.guardando}>Crear</Boton>
      </div>
    </form>
  );
}

// ---- Acciones de una obligación ----------------------------------------------------------------------------------------

const esquemaPago = z.object({
  monto: z.string().regex(IMPORTE, 'Importe válido, hasta 2 decimales.').refine((v) => Number(v) > 0, 'Debe ser mayor que cero.'),
  fecha_pago: z.string().min(1, 'Indica la fecha del pago.'),
  referencia: z.string().max(80, 'Hasta 80 caracteres.'),
});
const esquemaMotivo = z.object({ motivo: z.string().trim().min(10, 'Escribe al menos 10 caracteres.').max(200, 'Hasta 200 caracteres.') });

type Motivo = { tipo: 'rechazar' | 'reversar' | 'anular'; destino: string; titulo: string; boton: string };

/** Registrar pago (Yape), confirmar/rechazar/reversar transacciones y anular la obligación. Laravel decide y valida todo. */
export function AccionesObligacion({ obligacion: o }: { obligacion: ObligacionDetalle }) {
  const router = useRouter();
  const { mostrar } = useAvisos();
  const { ejecutar } = useOperacionPanel();
  const puedeRegistrar = usePuede('pagos.registrar');
  const puedeConfirmar = usePuede('pagos.confirmar');
  const puedeReversar = usePuede('pagos.reversar');
  const puedePlanificar = usePuede('pagos.planificar');
  const [pago, setPago] = useState(false);
  const [motivo, setMotivo] = useState<Motivo | null>(null);
  const [ocupado, setOcupado] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const vigente = o.estado !== 'anulada';

  async function confirmar(t: TransaccionPagoVista) {
    setError(null);
    const r = await ejecutar<{ message: string }>(`academico/pagos/transacciones/${t.identificador}/confirmar`, 'POST', {});
    if (!r.ok) return setError(r.error.message);
    mostrar('exito', r.datos.message);
    router.refresh();
  }

  return (
    <div className="space-y-4">
      {error ? <Alerta tipo="error">{error}</Alerta> : null}
      {vigente ? (
        <div className="flex flex-wrap gap-2">
          {puedeRegistrar && Number(o.saldo) - Number(o.pendiente_confirmar) > 0 ? <Boton icono={<Plus className="h-4 w-4" aria-hidden="true" />} onClick={() => setPago(true)}>Registrar pago</Boton> : null}
          {puedePlanificar ? <Boton variante="secundario" icono={<Ban className="h-4 w-4" aria-hidden="true" />} onClick={() => setMotivo({ tipo: 'anular', destino: `academico/pagos/obligaciones/${o.identificador}/anular`, titulo: 'Anular obligación', boton: 'Anular obligación' })}>Anular obligación</Boton> : null}
        </div>
      ) : null}

      {o.transacciones.length === 0 ? (
        <p className="text-sm text-panel-muted">Todavía no hay pagos registrados.</p>
      ) : (
        <ul className="divide-y divide-panel-border rounded-xl border border-panel-border bg-panel-surface">
          {o.transacciones.map((t) => (
            <li key={t.identificador} className="space-y-1 px-4 py-3">
              <div className="flex flex-wrap items-center justify-between gap-2">
                <p className="font-semibold text-panel-text">
                  {t.tipo === 'reverso' ? 'Reverso ' : 'Pago '}
                  {moneda(t.monto)} · {t.metodo} · {fecha(t.fecha_pago)}
                </p>
                <div className="flex gap-1">
                  <Insignia color={COLOR_TX[t.estado]}>{t.estado === 'confirmada' ? 'Confirmado' : t.estado === 'pendiente' ? 'Por confirmar' : 'Rechazado'}</Insignia>
                  {t.revertida ? <Insignia color="gris">Revertido</Insignia> : null}
                </div>
              </div>
              {t.referencia ? <p className="text-sm text-panel-muted">Referencia: {t.referencia}</p> : null}
              {t.motivo ? <p className="text-sm text-panel-muted">Motivo: {t.motivo}</p> : null}
              <div className="flex flex-wrap gap-1">
                {t.tipo === 'pago' ? <ComprobanteTransaccion transaccion={t} puedeAdjuntar={t.estado === 'pendiente' && puedeRegistrar && vigente} /> : null}
                {t.tipo === 'pago' && t.estado === 'pendiente' && puedeConfirmar && vigente ? (
                  <>
                    <Boton variante="fantasma" disabled={!t.tiene_comprobante} title={t.tiene_comprobante ? undefined : 'Adjunta primero la captura del comprobante'} icono={<Check className="h-4 w-4" aria-hidden="true" />} onClick={() => void confirmar(t)}>Confirmar</Boton>
                    <Boton variante="fantasma" icono={<X className="h-4 w-4" aria-hidden="true" />} onClick={() => setMotivo({ tipo: 'rechazar', destino: `academico/pagos/transacciones/${t.identificador}/rechazar`, titulo: 'Rechazar pago', boton: 'Rechazar' })}>Rechazar</Boton>
                  </>
                ) : null}
                {t.tipo === 'pago' && t.estado === 'confirmada' && !t.revertida && puedeReversar ? (
                  <Boton variante="fantasma" icono={<Undo2 className="h-4 w-4" aria-hidden="true" />} onClick={() => setMotivo({ tipo: 'reversar', destino: `academico/pagos/transacciones/${t.identificador}/reversar`, titulo: 'Reversar pago', boton: 'Reversar' })}>Reversar</Boton>
                ) : null}
              </div>
            </li>
          ))}
        </ul>
      )}

      <Dialogo abierto={pago} titulo="Registrar pago" descripcion={`Yape · saldo disponible ${moneda(String(Number(o.saldo) - Number(o.pendiente_confirmar)))}`} onCerrar={() => setPago(false)} bloqueado={ocupado}>
        {pago ? <FormularioPago obligacion={o} puedeConfirmar={puedeConfirmar} onOcupado={setOcupado} onCerrar={() => setPago(false)} /> : null}
      </Dialogo>
      <Dialogo abierto={motivo !== null} titulo={motivo?.titulo ?? ''} descripcion={motivo?.tipo === 'reversar' ? 'El pago original no se modifica: se registra una transacción compensatoria.' : undefined} onCerrar={() => setMotivo(null)} bloqueado={ocupado}>
        {motivo ? <FormularioMotivo motivo={motivo} onOcupado={setOcupado} onCerrar={() => setMotivo(null)} /> : null}
      </Dialogo>
    </div>
  );
}

function FormularioPago({ obligacion: o, puedeConfirmar, onOcupado, onCerrar }: { obligacion: ObligacionDetalle; puedeConfirmar: boolean; onOcupado: (b: boolean) => void; onCerrar: () => void }) {
  const router = useRouter();
  const { mostrar } = useAvisos();
  const { ejecutar, subir } = useOperacionPanel();
  const [confirmar, setConfirmar] = useState(false);
  const [captura, setCaptura] = useState<File | null>(null);
  const [errorCaptura, setErrorCaptura] = useState<string | null>(null);
  // Una intención = una clave: si la red falla y se reintenta, Laravel devuelve el pago original sin duplicarlo.
  const claveIntento = useRef(clave());
  const f = useFormularioPanel({
    esquema: esquemaPago,
    valores: { monto: '', fecha_pago: hoyLima(), referencia: '' },
    onOcupado,
    enviar: async (d) => {
      setErrorCaptura(null);
      const problema = validarCaptura(captura);
      if (problema || captura === null) {
        setErrorCaptura(problema ?? 'Adjunta la captura del comprobante.');
        return { ok: false as const, estado: 422, error: { message: 'Falta la captura del comprobante.', code: 'captura' } };
      }
      const registro = await ejecutar<{ message: string; data: ObligacionDetalle }>(`academico/pagos/obligaciones/${o.identificador}/pagos`, 'POST', {
        metodo: 'yape', monto: d.monto, fecha_pago: d.fecha_pago, referencia: d.referencia === '' ? null : d.referencia, _clave: claveIntento.current,
      });
      if (!registro.ok) return registro;

      // El pago más reciente por confirmar es el recién registrado (o el original si fue una repetición idempotente).
      const nuevo = [...registro.datos.data.transacciones].reverse().find((t) => t.tipo === 'pago' && t.estado === 'pendiente');
      if (!nuevo) return registro;
      const formulario = new FormData();
      formulario.append('imagen', captura);
      const subida = await subir<{ message: string }>(`academico/pagos/transacciones/${nuevo.identificador}/comprobante`, formulario);
      if (!subida.ok) {
        router.refresh();
        return { ok: false as const, estado: subida.estado, error: { message: `El pago se registró, pero no se pudo adjuntar la captura: ${subida.error.message} Adjúntala desde la lista de pagos.`, code: 'captura' } };
      }
      if (confirmar && puedeConfirmar) {
        const confirmacion = await ejecutar<{ message: string }>(`academico/pagos/transacciones/${nuevo.identificador}/confirmar`, 'POST', {});
        if (!confirmacion.ok) {
          router.refresh();
          return { ok: false as const, estado: confirmacion.estado, error: { message: `El pago y la captura se registraron, pero no se pudo confirmar: ${confirmacion.error.message}`, code: 'confirmacion' } };
        }
      }
      return registro;
    },
    onExito: (r) => {
      onCerrar();
      mostrar('exito', r.message);
      router.refresh();
    },
  });

  return (
    <form onSubmit={f.enviar} noValidate className="space-y-4">
      {f.errorGeneral ? <Alerta tipo="error">{f.errorGeneral}</Alerta> : null}
      <div className="grid gap-4 sm:grid-cols-2">
        <Campo etiqueta="Importe (S/)" inputMode="decimal" {...f.registro('monto')} error={f.error('monto')} />
        <Campo etiqueta="Fecha del pago" type="date" {...f.registro('fecha_pago')} error={f.error('fecha_pago')} />
      </div>
      <Campo etiqueta="Código de operación Yape (opcional)" {...f.registro('referencia')} error={f.error('referencia')} />
      <Campo
        etiqueta="Captura del comprobante de Yape"
        type="file"
        accept="image/jpeg,image/png,image/webp"
        onChange={(e) => setCaptura(e.target.files?.[0] ?? null)}
        error={errorCaptura ?? undefined}
        ayuda="JPG, PNG o WebP de hasta 3 MB. Es obligatoria."
      />
      {puedeConfirmar ? (
        <label className="flex items-center gap-2 text-sm text-panel-text">
          <input type="checkbox" checked={confirmar} onChange={(e) => setConfirmar(e.target.checked)} className="h-4 w-4 rounded border-panel-border accent-academico-700" />
          Confirmar el pago ahora
        </label>
      ) : (
        <p className="text-xs text-panel-muted">El pago quedará «por confirmar» hasta que lo confirme otra persona autorizada.</p>
      )}
      <div className="flex flex-col-reverse gap-2 border-t border-panel-border pt-4 sm:flex-row sm:justify-end">
        <Boton variante="secundario" onClick={onCerrar} disabled={f.guardando}>Cancelar</Boton>
        <Boton type="submit" cargando={f.guardando}>Registrar</Boton>
      </div>
    </form>
  );
}

const MAX_CAPTURA = 3 * 1024 * 1024;

/** Validación de ayuda en el navegador; Laravel vuelve a validar el tipo real y el tamaño. */
function validarCaptura(archivo: File | null): string | null {
  if (archivo === null) return 'Adjunta la captura del comprobante.';
  if (!['image/jpeg', 'image/png', 'image/webp'].includes(archivo.type)) return 'Sube una captura en JPG, PNG o WebP.';
  if (archivo.size > MAX_CAPTURA) return 'La captura no puede pasar de 3 MB.';
  return null;
}

/** Ver la captura del comprobante (solo con `pagos.ver`) y adjuntar o reemplazar la de un pago por confirmar. */
function ComprobanteTransaccion({ transaccion: t, puedeAdjuntar }: { transaccion: TransaccionPagoVista; puedeAdjuntar: boolean }) {
  const router = useRouter();
  const { mostrar } = useAvisos();
  const { subir } = useOperacionPanel();
  const puedeVer = usePuede('pagos.ver');
  const [viendo, setViendo] = useState(false);
  const [subiendo, setSubiendo] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const entrada = useRef<HTMLInputElement>(null);

  async function adjuntar(archivo: File | undefined) {
    if (!archivo) return;
    const problema = validarCaptura(archivo);
    if (problema) return setError(problema);
    setSubiendo(true);
    setError(null);
    const formulario = new FormData();
    formulario.append('imagen', archivo);
    const r = await subir<{ message: string }>(`academico/pagos/transacciones/${t.identificador}/comprobante`, formulario);
    setSubiendo(false);
    if (entrada.current) entrada.current.value = '';
    if (!r.ok) return setError(r.error.message);
    mostrar('exito', r.datos.message);
    router.refresh();
  }

  return (
    <>
      {error ? <p className="w-full text-sm text-estado-error">{error}</p> : null}
      {t.tiene_comprobante && puedeVer ? <Boton variante="fantasma" onClick={() => setViendo(true)}>Ver comprobante</Boton> : null}
      {puedeAdjuntar ? (
        <>
          <input ref={entrada} type="file" accept="image/jpeg,image/png,image/webp" className="sr-only" aria-label="Elegir captura del comprobante" onChange={(e) => void adjuntar(e.target.files?.[0])} />
          <Boton variante="fantasma" cargando={subiendo} onClick={() => entrada.current?.click()}>{t.tiene_comprobante ? 'Reemplazar comprobante' : 'Adjuntar comprobante'}</Boton>
        </>
      ) : null}
      <Dialogo abierto={viendo} titulo="Comprobante de pago" onCerrar={() => setViendo(false)}>
        {viendo ? (
          <p className="mb-3 text-right">
            <a href={`/api/bff/panel/academico/pagos/transacciones/${t.identificador}/comprobante`} download={`comprobante-de-pago-${t.identificador}`} className="text-sm font-semibold text-panel-text underline">
              Descargar imagen
            </a>
          </p>
        ) : null}
        {viendo ? (
          // Imagen privada servida por el BFF con la sesión: no hay URL pública; `no-store` y `nosniff` los pone Laravel.
          // eslint-disable-next-line @next/next/no-img-element
          <img src={`/api/bff/panel/academico/pagos/transacciones/${t.identificador}/comprobante`} alt="Captura del comprobante de pago" className="mx-auto max-h-[70vh] w-auto rounded-lg border border-panel-border" />
        ) : null}
      </Dialogo>
    </>
  );
}

function FormularioMotivo({ motivo, onOcupado, onCerrar }: { motivo: Motivo; onOcupado: (b: boolean) => void; onCerrar: () => void }) {
  const router = useRouter();
  const { mostrar } = useAvisos();
  const { ejecutar } = useOperacionPanel();
  const f = useFormularioPanel({
    esquema: esquemaMotivo,
    valores: { motivo: '' },
    onOcupado,
    enviar: (d) => ejecutar<{ message: string }>(motivo.destino, 'POST', { motivo: d.motivo }),
    onExito: (r) => {
      onCerrar();
      mostrar('exito', r.message);
      router.refresh();
    },
  });

  return (
    <form onSubmit={f.enviar} noValidate className="space-y-4">
      {f.errorGeneral ? <Alerta tipo="error">{f.errorGeneral}</Alerta> : null}
      <Campo etiqueta="Motivo" {...f.registro('motivo')} error={f.error('motivo')} maxLength={200} />
      <div className="flex flex-col-reverse gap-2 border-t border-panel-border pt-4 sm:flex-row sm:justify-end">
        <Boton variante="secundario" onClick={onCerrar} disabled={f.guardando}>Volver</Boton>
        <Boton type="submit" cargando={f.guardando}>{motivo.boton}</Boton>
      </div>
    </form>
  );
}

/** Enlace a la obligación de la matrícula (o a la lista de pagos). */
export function EnlaceObligacion({ identificador }: { identificador: string }) {
  return <Link href={`/admin/academico/pagos/${identificador}`} className="font-semibold text-academico-700 hover:underline">Ver pagos y cuotas</Link>;
}

export { COLOR_COBRO };

/**
 * «Comprobante de pago» de la ficha de la matrícula: cada pago con su estado y su captura (ver, descargar, adjuntar o reemplazar si
 * el pago sigue por confirmar). Confirmar o rechazar sigue en la página del pago, donde queda el detalle completo.
 */
export function ComprobantesDeLaMatricula({ obligacion: o }: { obligacion: ObligacionDetalle }) {
  const puedeRegistrar = usePuede('pagos.registrar');
  const pagos = o.transacciones.filter((t) => t.tipo === 'pago');
  const vigente = o.estado !== 'anulada';

  if (pagos.length === 0) {
    return <p className="text-sm text-panel-muted">Todavía no hay pagos registrados. Cuando se registre un pago podrás adjuntar aquí su comprobante.</p>;
  }

  return (
    <ul className="divide-y divide-panel-border rounded-xl border border-panel-border bg-panel-surface">
      {pagos.map((t) => (
        <li key={t.identificador} className="space-y-2 px-4 py-3">
          <div className="flex flex-wrap items-center justify-between gap-2">
            <p className="text-sm font-semibold text-panel-text">
              {moneda(t.monto)} · {t.metodo} · {fecha(t.fecha_pago)}
            </p>
            <div className="flex gap-1">
              <Insignia color={COLOR_TX[t.estado]}>{t.estado === 'confirmada' ? 'Confirmado' : t.estado === 'pendiente' ? 'Por confirmar' : 'Rechazado'}</Insignia>
              <Insignia color={t.tiene_comprobante ? 'verde' : 'gris'}>{t.tiene_comprobante ? 'Con comprobante' : 'Sin comprobante'}</Insignia>
            </div>
          </div>
          {t.referencia ? <p className="text-xs text-panel-muted">N.º de operación: {t.referencia}</p> : null}
          <div className="flex flex-wrap gap-1">
            <ComprobanteTransaccion transaccion={t} puedeAdjuntar={t.estado === 'pendiente' && puedeRegistrar && vigente} />
          </div>
        </li>
      ))}
    </ul>
  );
}

/** Exporta las obligaciones a Excel (solo con `pagos.exportar`). */
export function BotonExportarPagos() {
  const { descargar } = useOperacionPanel();
  const puede = usePuede('pagos.exportar');
  const [ocupado, setOcupado] = useState(false);
  const [error, setError] = useState<string | null>(null);
  if (!puede) return null;

  async function exportar() {
    setOcupado(true);
    setError(null);
    const r = await descargar('academico/pagos/exportar');
    setOcupado(false);
    if (!r.ok) setError(r.error.message);
  }

  return (
    <div className="space-y-2">
      {error ? <Alerta tipo="error">{error}</Alerta> : null}
      <Boton variante="secundario" cargando={ocupado} onClick={() => void exportar()}>Exportar Excel</Boton>
    </div>
  );
}
