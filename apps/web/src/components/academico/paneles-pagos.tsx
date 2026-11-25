'use client';

import { ArrowUpRight, Ban, Check, CirclePlus, Copy, Download, Eye, FileText, MessageCircle, Plus, Trash2, Undo2, Upload, X } from 'lucide-react';
import Link from 'next/link';
import { useRouter } from 'next/navigation';
import { useEffect, useRef, useState } from 'react';
import { z } from 'zod';
import { usePuede } from '@/components/shared/sesion-panel-contexto';
import { useFormularioPanel } from '@/components/shared/use-formulario-panel';
import { useOperacionPanel } from '@/components/shared/use-operacion-panel';
import { Alerta } from '@/components/shared/ui/alerta';
import { useAvisos } from '@/components/shared/ui/avisos';
import { Boton } from '@/components/shared/ui/boton';
import { Campo, CampoArchivo, CampoSeleccion } from '@/components/shared/ui/campo';
import { Casilla } from '@/components/shared/ui/casilla';
import { Dialogo } from '@/components/shared/ui/dialogo';
import { Insignia } from '@/components/shared/ui/insignia';
import type { ImagenRepetida, ObligacionDetalle, TransaccionPagoVista } from '@/features/academico/tipos';
import { ACEPTA_CAPTURA, IMPORTE, normalizarImporte, validarCaptura } from '@/lib/panel/captura';
import { fecha, hoyLima, moneda } from '@/lib/panel/formato';
import { copiarAlPortapapeles, enlaceWaMe, mensajeRecordatorioCuota } from '@/lib/panel/whatsapp-plantillas';

const COLOR_COBRO = { pendiente: 'ambar', parcial: 'azul', pagada: 'verde', anulada: 'rojo' } as const;
const COLOR_TX = { pendiente: 'ambar', confirmada: 'verde', rechazada: 'rojo' } as const;
export const ETIQUETA_COBRO = { pendiente: 'Pendiente', parcial: 'Parcial', pagada: 'Pagada', anulada: 'Anulada' } as const;

const MOTIVOS_FRECUENTES = [
  'Comprobante ilegible o incompleto',
  'Monto no coincide con la captura',
  'Operación duplicada o inexistente',
  'Solicitud de anulación del estudiante',
] as const;
const clave = () => crypto.randomUUID();

// ---- Obligación desde la ficha de la matrícula ------------------------------------------------------------------------

const esquemaObligacion = z.object({
  concepto: z.string().trim().min(3, 'Indica el concepto.').max(120, 'Hasta 120 caracteres.'),
  plan: z.enum(['completo', 'fraccionado']),
  monto_total: z.string().regex(IMPORTE, 'Importe válido, hasta 2 decimales (por ejemplo 25,50).').refine((v) => Number(v) > 0, 'Debe ser mayor que cero.'),
});

/** Crea la obligación de pago de una matrícula (completo o fraccionado). La suma de cuotas se valida aquí y en Laravel. */
export function BotonCrearObligacion({
  matriculaId,
  lado = 'derecha',
  ancho = 'lg',
}: {
  matriculaId: number;
  lado?: 'centro' | 'derecha';
  ancho?: 'sm' | 'lg' | 'xl';
}) {
  const puede = usePuede('pagos.planificar');
  const [abierto, setAbierto] = useState(false);
  const [ocupado, setOcupado] = useState(false);
  if (!puede) return null;
  return (
    <>
      <Boton icono={<Plus className="h-4 w-4" aria-hidden="true" />} onClick={() => setAbierto(true)}>Crear obligación de pago</Boton>
      <Dialogo abierto={abierto} lado={lado} ancho={ancho} titulo="Obligación de pago" descripcion="Solo soles (PEN), monto fijo." onCerrar={() => setAbierto(false)} bloqueado={ocupado}>
        {abierto ? <FormularioObligacion matriculaId={matriculaId} onOcupado={setOcupado} onCerrar={() => setAbierto(false)} /> : null}
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
        <Campo etiqueta="Monto total (S/)" inputMode="decimal" required ayuda="Puedes escribir coma o punto: 250,00" {...f.registro('monto_total', { setValueAs: normalizarImporte })} error={f.error('monto_total')} />
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
      <div className="pie-acciones">
        <Boton variante="secundario" onClick={onCerrar} disabled={f.guardando}>Cancelar</Boton>
        <Boton type="submit" cargando={f.guardando}>Crear</Boton>
      </div>
    </form>
  );
}

// ---- Acciones de una obligación ----------------------------------------------------------------------------------------

const esquemaPago = z.object({
  metodo: z.string().min(1, 'Elige el método de pago.'),
  monto: z.string().regex(IMPORTE, 'Importe válido, hasta 2 decimales (por ejemplo 25,50).').refine((v) => Number(v) > 0, 'Debe ser mayor que cero.'),
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
  // Antes de confirmar, quien lo hace comprueba en su app (Yape o banco) que la operación existe: una captura puede estar editada.
  const [porVerificar, setPorVerificar] = useState<TransaccionPagoVista | null>(null);
  const [confirmando, setConfirmando] = useState(false);
  const vigente = o.estado !== 'anulada';

  async function confirmar(t: TransaccionPagoVista) {
    setError(null);
    setConfirmando(true);
    const r = await ejecutar<{ message: string }>(`academico/pagos/transacciones/${t.identificador}/confirmar`, 'POST', {});
    setConfirmando(false);
    setPorVerificar(null);
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
          {Number(o.saldo) > 0 ? (() => {
            const textoRecordatorio = mensajeRecordatorioCuota({
              estudiante: o.estudiante.nombre,
              curso: o.concepto,
              saldo: o.saldo,
              vence: o.proxima_cuota ? fecha(o.proxima_cuota.vence) : null,
            });
            const urlWa = enlaceWaMe(o.estudiante.telefono, textoRecordatorio);
            return (
              <>
                <Boton
                  variante="fantasma"
                  icono={<Copy className="h-4 w-4" aria-hidden="true" />}
                  onClick={async () => {
                    const ok = await copiarAlPortapapeles(textoRecordatorio);
                    mostrar(ok ? 'exito' : 'error', ok ? 'Recordatorio de pago copiado para WhatsApp.' : 'No se pudo copiar.');
                  }}
                >
                  Copiar recordatorio
                </Boton>
                {urlWa ? (
                  <a
                    href={urlWa}
                    target="_blank"
                    rel="noopener noreferrer"
                    className="inline-flex items-center justify-center gap-2 rounded-lg border border-emerald-600/30 bg-emerald-50 px-3.5 py-2 text-sm font-semibold text-emerald-800 transition hover:bg-emerald-100"
                  >
                    <MessageCircle className="h-4 w-4" aria-hidden="true" /> Recordar WhatsApp
                  </a>
                ) : null}
              </>
            );
          })() : null}
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
              {t.tipo === 'pago' && t.estado !== 'rechazada' && t.imagen_repetida ? <AvisoCapturaRepetida repetida={t.imagen_repetida} /> : null}
              <div className="flex flex-wrap gap-1">
                {t.tipo === 'pago' ? <ComprobanteTransaccion transaccion={t} puedeAdjuntar={t.estado === 'pendiente' && puedeRegistrar && vigente} /> : null}
                {t.tipo === 'pago' && t.estado === 'pendiente' && puedeConfirmar && vigente ? (
                  <>
                    <Boton variante="fantasma" disabled={!t.tiene_comprobante} title={t.tiene_comprobante ? undefined : 'Adjunta primero la captura del comprobante'} icono={<Check className="h-4 w-4" aria-hidden="true" />} onClick={() => setPorVerificar(t)}>Confirmar</Boton>
                    <Boton variante="fantasma" icono={<X className="h-4 w-4" aria-hidden="true" />} onClick={() => setMotivo({ tipo: 'rechazar', destino: `academico/pagos/transacciones/${t.identificador}/rechazar`, titulo: 'Rechazar pago', boton: 'Rechazar' })}>Rechazar</Boton>
                  </>
                ) : null}
                {t.tipo === 'pago' && t.estado === 'confirmada' && !t.revertida && puedeReversar ? (
                  // Desde el umbral, el reverso necesita la aprobación de Gerencia: primero se solicita; cuando la aprueban, se reversa.
                  t.reverso_aprobacion === 'pendiente' ? (
                    <Boton variante="fantasma" disabled icono={<Undo2 className="h-4 w-4" aria-hidden="true" />} title="Gerencia aún no resuelve la solicitud (Aprobaciones)">Aprobación pendiente</Boton>
                  ) : t.reverso_aprobacion === 'ninguna' || t.reverso_aprobacion === 'rechazado' ? (
                    <Boton variante="fantasma" icono={<Undo2 className="h-4 w-4" aria-hidden="true" />} onClick={() => setMotivo({ tipo: 'reversar', destino: `academico/pagos/transacciones/${t.identificador}/reversar/solicitar`, titulo: 'Solicitar aprobación del reverso', boton: 'Solicitar aprobación' })}>Solicitar reverso</Boton>
                  ) : (
                    <Boton variante="fantasma" icono={<Undo2 className="h-4 w-4" aria-hidden="true" />} onClick={() => setMotivo({ tipo: 'reversar', destino: `academico/pagos/transacciones/${t.identificador}/reversar`, titulo: 'Reversar pago', boton: 'Reversar' })}>Reversar</Boton>
                  )
                ) : null}
              </div>
            </li>
          ))}
        </ul>
      )}

      <Dialogo abierto={pago} lado="derecha" titulo="Registrar pago" descripcion={`Yape · saldo disponible ${moneda(String(Number(o.saldo) - Number(o.pendiente_confirmar)))}`} onCerrar={() => setPago(false)} bloqueado={ocupado}>
        {pago ? <FormularioPago obligacion={o} puedeConfirmar={puedeConfirmar} onOcupado={setOcupado} onCerrar={() => setPago(false)} /> : null}
      </Dialogo>
      <Dialogo abierto={porVerificar !== null} titulo="Antes de confirmar" descripcion="Comprueba el pago en tu app, no solo en la captura." onCerrar={() => setPorVerificar(null)} bloqueado={confirmando}>
        {porVerificar ? (
          <div className="space-y-4">
            <p className="text-sm text-panel-text">
              Abre tu app de Yape (o la del banco) y comprueba que existe una operación{porVerificar.referencia ? <> con código <strong className="font-mono">{porVerificar.referencia}</strong></> : ''} por{' '}
              <strong>{moneda(porVerificar.monto)}</strong> del {fecha(porVerificar.fecha_pago)}. Una captura puede estar editada; confirma solo si el dinero ya está en tu cuenta.
            </p>
            {porVerificar.imagen_repetida ? <AvisoCapturaRepetida repetida={porVerificar.imagen_repetida} /> : null}
            <div className="pie-acciones">
              <Boton variante="secundario" onClick={() => setPorVerificar(null)} disabled={confirmando}>Volver</Boton>
              <Boton cargando={confirmando} icono={<Check className="h-4 w-4" aria-hidden="true" />} onClick={() => void confirmar(porVerificar)}>Ya lo verifiqué: confirmar</Boton>
            </div>
          </div>
        ) : null}
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
  const [metodos, setMetodos] = useState<{ codigo: string; nombre: string }[]>([{ codigo: 'yape', nombre: 'Yape' }]);
  const [confirmar, setConfirmar] = useState(false);
  const [captura, setCaptura] = useState<File | null>(null);
  const [errorCaptura, setErrorCaptura] = useState<string | null>(null);
  // Una intención = una clave: si la red falla y se reintenta, Laravel devuelve el pago original sin duplicarlo.
  const claveIntento = useRef(clave());
  const f = useFormularioPanel({
    esquema: esquemaPago,
    valores: { metodo: 'yape', monto: '', fecha_pago: hoyLima(), referencia: '' },
    onOcupado,
    enviar: async (d) => {
      setErrorCaptura(null);
      const problema = validarCaptura(captura);
      if (problema || captura === null) {
        setErrorCaptura(problema ?? 'Adjunta la captura del comprobante.');
        return { ok: false as const, estado: 422, error: { message: 'Falta la captura del comprobante.', code: 'captura' } };
      }
      const registro = await ejecutar<{ message: string; data: ObligacionDetalle }>(`academico/pagos/obligaciones/${o.identificador}/pagos`, 'POST', {
        metodo: d.metodo, monto: d.monto, fecha_pago: d.fecha_pago, referencia: d.referencia === '' ? null : d.referencia, _clave: claveIntento.current,
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

  useEffect(() => {
    let activo = true;
    void ejecutar<{ data: { codigo: string; nombre: string }[] }>('academico/pagos/metodos').then((r) => {
      if (!activo || !r.ok || r.datos.data.length === 0) return;
      setMetodos(r.datos.data);
      const actual = f.form.getValues('metodo');
      if (!r.datos.data.some((m) => m.codigo === actual)) {
        f.form.setValue('metodo', r.datos.data[0].codigo);
      }
    });
    return () => {
      activo = false;
    };
  }, [ejecutar, f.form]);

  return (
    <form onSubmit={f.enviar} noValidate className="space-y-4">
      {f.errorGeneral ? <Alerta tipo="error">{f.errorGeneral}</Alerta> : null}
      <div className="grid gap-4 sm:grid-cols-2">
        <Campo
          etiqueta="Importe (S/)"
          inputMode="decimal"
          required
          ayuda="Puedes escribir coma o punto: 25,50"
          {...f.registro('monto', { setValueAs: normalizarImporte })}
          error={f.error('monto')}
        />
        <Campo etiqueta="Fecha del pago" type="date" {...f.registro('fecha_pago')} error={f.error('fecha_pago')} />
      </div>
      <div className="grid gap-4 sm:grid-cols-2">
        <CampoSeleccion
          etiqueta="Método de pago"
          {...f.registro('metodo')}
          error={f.error('metodo')}
          opciones={Object.fromEntries(metodos.map((m) => [m.codigo, m.nombre]))}
        />
        <Campo etiqueta="Código de operación (opcional)" {...f.registro('referencia')} error={f.error('referencia')} />
      </div>
      <CampoArchivo
        etiqueta="Captura del comprobante de pago"
        requerido
        accept={ACEPTA_CAPTURA}
        onArchivo={setCaptura}
        error={errorCaptura ?? undefined}
        ayuda="JPG, PNG, WebP o PDF de hasta 3 MB. Es obligatorio."
      />
      {puedeConfirmar ? (
        <Casilla etiqueta="Confirmar el pago ahora" checked={confirmar} onChange={(e) => setConfirmar(e.target.checked)} />
      ) : (
        <p className="text-xs text-panel-muted">El pago quedará «por confirmar» hasta que lo confirme otra persona autorizada.</p>
      )}
      <div className="pie-acciones">
        <Boton variante="secundario" onClick={onCerrar} disabled={f.guardando}>Cancelar</Boton>
        <Boton type="submit" cargando={f.guardando}>Registrar</Boton>
      </div>
    </form>
  );
}

/** Aviso (no bloquea): la misma captura ya respalda otro pago vigente. Decide quien confirma. */
function AvisoCapturaRepetida({ repetida }: { repetida: ImagenRepetida }) {
  return (
    <Alerta tipo="error">
      Esta misma captura ya respalda un pago de la matrícula #{repetida.matricula_id} ({fecha(repetida.fecha_pago)}). Puede ser un error o un intento de cobrar dos veces:
      revisa ambos pagos antes de confirmar.
    </Alerta>
  );
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

  const claseAccionComprobante =
    'inline-flex h-8 items-center justify-center gap-1.5 rounded-lg border border-panel-border bg-panel-surface px-2.5 text-xs font-semibold text-panel-text transition hover:border-academico-300 hover:bg-academico-50 hover:text-academico-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-academico-700 disabled:cursor-not-allowed disabled:opacity-60 cursor-pointer';

  return (
    <>
      {error ? <p role="alert" className="w-full text-sm text-estado-error">{error}</p> : null}
      {t.tiene_comprobante && puedeVer && t.tipo_comprobante === 'pdf' ? (
        // Un PDF solo se descarga: nunca se abre dentro del navegador.
        <a
          href={`/api/bff/panel/academico/pagos/transacciones/${t.identificador}/comprobante`}
          download="comprobante-de-pago.pdf"
          className={claseAccionComprobante}
        >
          <Download className="h-3.5 w-3.5" aria-hidden="true" />
          Descargar PDF
        </a>
      ) : null}
      {t.tiene_comprobante && puedeVer && t.tipo_comprobante !== 'pdf' ? (
        <button type="button" onClick={() => setViendo(true)} className={claseAccionComprobante}>
          <Eye className="h-3.5 w-3.5" aria-hidden="true" />
          Ver comprobante
        </button>
      ) : null}
      {puedeAdjuntar ? (
        <>
          <input ref={entrada} type="file" accept="image/jpeg,image/png,image/webp,application/pdf" className="sr-only" aria-label="Elegir comprobante (captura o PDF)" onChange={(e) => void adjuntar(e.target.files?.[0])} />
          <button type="button" disabled={subiendo} onClick={() => entrada.current?.click()} className={claseAccionComprobante}>
            <Upload className="h-3.5 w-3.5" aria-hidden="true" />
            {subiendo ? 'Subiendo…' : t.tiene_comprobante ? 'Reemplazar comprobante' : 'Adjuntar comprobante'}
          </button>
        </>
      ) : null}
      <Dialogo abierto={viendo} titulo="Comprobante de pago" onCerrar={() => setViendo(false)}>
        {viendo ? (
          <div className="space-y-3">
            <div className="flex justify-end">
              <a
                href={`/api/bff/panel/academico/pagos/transacciones/${t.identificador}/comprobante`}
                download={`comprobante-de-pago-${t.identificador}`}
                className={claseAccionComprobante}
              >
                <Download className="h-3.5 w-3.5" aria-hidden="true" />
                Descargar imagen
              </a>
            </div>
            {/* Imagen privada servida por el BFF con la sesión: no hay URL pública; `no-store` y `nosniff` los pone Laravel. */}
            {/* eslint-disable-next-line @next/next/no-img-element */}
            <img src={`/api/bff/panel/academico/pagos/transacciones/${t.identificador}/comprobante`} alt="Captura del comprobante de pago" className="mx-auto max-h-[70vh] w-auto rounded-lg border border-panel-border" />
          </div>
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
      <div className="space-y-1.5">
        <p className="text-xs font-medium text-panel-muted">Motivos frecuentes</p>
        <div className="flex flex-wrap gap-1.5">
          {MOTIVOS_FRECUENTES.map((texto) => (
            <button
              key={texto}
              type="button"
              onClick={() => f.form.setValue('motivo', texto, { shouldValidate: true, shouldDirty: true })}
              className="rounded-lg border border-panel-border bg-panel-bg px-2.5 py-1 text-xs font-medium text-panel-text transition hover:border-academico-400 hover:bg-academico-50"
            >
              {texto}
            </button>
          ))}
        </div>
      </div>
      <Campo etiqueta="Motivo" {...f.registro('motivo')} error={f.error('motivo')} maxLength={200} />
      <div className="pie-acciones">
        <Boton variante="secundario" onClick={onCerrar} disabled={f.guardando}>Volver</Boton>
        <Boton type="submit" variante="peligro" cargando={f.guardando}>{motivo.boton}</Boton>
      </div>
    </form>
  );
}

/** Enlace a la obligación de la matrícula (o a la lista de pagos). */
export function EnlaceObligacion({ identificador, etiqueta = 'Ver pagos y cuotas' }: { identificador: string; etiqueta?: string }) {
  return (
    <Link
      href={`/admin/academico/pagos/${identificador}`}
      className="inline-flex items-center justify-center gap-1.5 rounded-lg border border-panel-border bg-panel-surface px-4 py-2.5 text-sm font-semibold text-panel-text transition hover:border-academico-300 hover:bg-academico-50 hover:text-academico-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-academico-700"
    >
      {etiqueta}
      <ArrowUpRight className="h-4 w-4 text-panel-muted" aria-hidden="true" />
    </Link>
  );
}

export { COLOR_COBRO };

/**
 * Módulo financiero unificado para la ficha de matrícula: cifras tabulares (Total / Pagado / Saldo),
 * barra de progreso y lista limpia de abonos y comprobantes sin tarjetas anidadas.
 */
export function ResumenFinancieroMatricula({ obligacion: o }: { obligacion: ObligacionDetalle }) {
  const puedeRegistrar = usePuede('pagos.registrar');
  const pagos = o.transacciones.filter((t) => t.tipo === 'pago');
  const vigente = o.estado !== 'anulada';
  const totalNum = Number.parseFloat(o.monto_total) || 0;
  const pagadoNum = Number.parseFloat(o.pagado) || 0;
  const saldoNum = Number.parseFloat(o.saldo) || 0;
  const pendienteConfirmarNum = Number.parseFloat(o.pendiente_confirmar) || 0;
  const porcentaje = totalNum > 0 ? Math.min(100, Math.round((pagadoNum / totalNum) * 100)) : 0;

  return (
    <div className="space-y-5">
      {/* Cifras financieras y progreso */}
      <div className="space-y-3">
        <div className="grid grid-cols-1 divide-y divide-panel-border rounded-lg border border-panel-border bg-panel-bg/60 sm:grid-cols-3 sm:divide-x sm:divide-y-0">
          <div className="px-3.5 py-2.5">
            <p className="text-xs font-medium text-panel-muted">Total</p>
            <p className="mt-0.5 text-base font-semibold tabular-nums text-panel-text">{moneda(o.monto_total)}</p>
          </div>
          <div className="px-3.5 py-2.5">
            <p className="text-xs font-medium text-panel-muted">Pagado</p>
            <p className={`mt-0.5 text-base font-semibold tabular-nums ${pagadoNum > 0 ? 'text-estado-exito' : 'text-panel-text'}`}>
              {moneda(o.pagado)}
            </p>
          </div>
          <div className="px-3.5 py-2.5">
            <p className="text-xs font-medium text-panel-muted">Saldo pendiente</p>
            <p className={`mt-0.5 text-base font-semibold tabular-nums ${saldoNum > 0 ? 'text-estado-aviso' : 'text-panel-muted'}`}>
              {moneda(o.saldo)}
            </p>
          </div>
        </div>

        <div className="space-y-1.5">
          <div className="flex items-center justify-between gap-2 text-xs">
            <span className="truncate font-medium text-panel-muted">{o.concepto}</span>
            <span className="shrink-0 font-semibold tabular-nums text-panel-text">{porcentaje} %</span>
          </div>
          <div className="h-2 w-full overflow-hidden rounded-full bg-panel-border" role="progressbar" aria-valuenow={porcentaje} aria-valuemin={0} aria-valuemax={100} aria-label="Progreso de pago">
            <div
              className={`h-full rounded-full transition-all ${porcentaje >= 100 ? 'bg-estado-exito' : 'bg-academico-700'}`}
              style={{ width: `${porcentaje}%` }}
            />
          </div>
          {pendienteConfirmarNum > 0 ? (
            <p className="text-xs font-medium text-estado-aviso tabular-nums">
              Hay {moneda(o.pendiente_confirmar)} en abonos pendientes de confirmación.
            </p>
          ) : null}
        </div>
      </div>

      {/* Lista integrada de pagos y comprobantes */}
      <div className="border-t border-panel-border pt-4">
        <div className="mb-2 flex items-center justify-between gap-2">
          <h3 className="text-sm font-semibold text-panel-text">
            Comprobantes y abonos {pagos.length > 0 ? <span className="font-normal tabular-nums text-panel-muted">({pagos.length})</span> : null}
          </h3>
        </div>

        {pagos.length === 0 ? (
          <p className="text-sm text-panel-muted">
            Todavía no hay abonos registrados. Registra pagos o cuotas desde{' '}
            <Link href={`/admin/academico/pagos/${o.identificador}`} className="font-semibold text-academico-700 hover:underline">
              Pagos y cuotas
            </Link>
            .
          </p>
        ) : (
          <ul className="divide-y divide-panel-border">
            {pagos.map((t) => (
              <li key={t.identificador} className="space-y-2 py-3 first:pt-1 last:pb-0">
                <div className="flex flex-wrap items-start justify-between gap-2">
                  <div>
                    <p className="text-sm font-semibold tabular-nums text-panel-text">
                      {moneda(t.monto)}{' '}
                      <span className="font-normal text-panel-muted">
                        · {t.metodo} · {fecha(t.fecha_pago)}
                      </span>
                    </p>
                    {t.referencia ? <p className="mt-0.5 font-mono text-xs text-panel-muted">Op. {t.referencia}</p> : null}
                  </div>
                  <div className="flex flex-wrap gap-1">
                    <Insignia color={COLOR_TX[t.estado]}>
                      {t.estado === 'confirmada' ? 'Confirmado' : t.estado === 'pendiente' ? 'Por confirmar' : 'Rechazado'}
                    </Insignia>
                    {!t.tiene_comprobante ? <Insignia color="gris">Sin captura</Insignia> : null}
                    {t.imagen_repetida && t.estado !== 'rechazada' ? <Insignia color="rojo">Captura repetida</Insignia> : null}
                  </div>
                </div>
                <div className="flex flex-wrap items-center gap-2">
                  <ComprobanteTransaccion transaccion={t} puedeAdjuntar={t.estado === 'pendiente' && puedeRegistrar && vigente} />
                </div>
              </li>
            ))}
          </ul>
        )}
      </div>
    </div>
  );
}

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
              {t.imagen_repetida && t.estado !== 'rechazada' ? <Insignia color="rojo">Captura repetida</Insignia> : null}
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

interface FilaCierreMetodo { codigo: string; metodo: string; cantidad: number; cantidad_reversos: number; total: string }
interface ResumenCaja {
  fecha: string;
  cantidad: number;
  cantidad_reversos: number;
  cobrado: string;
  reversado: string;
  total: string;
  pendientes_por_confirmar: number;
  por_metodo: FilaCierreMetodo[];
  cierre: { total_esperado: string; total_declarado: string; diferencia: string; cerrado_por: string | null } | null;
}

/** Cierre de caja del día (`pagos.cerrar_caja`): muestra lo esperado por método, pide lo contado y exige explicar una diferencia. */
function BotonCerrarCaja() {
  const puede = usePuede('pagos.cerrar_caja');
  const { ejecutar } = useOperacionPanel();
  const { mostrar } = useAvisos();
  const [abierto, setAbierto] = useState(false);
  const [ocupado, setOcupado] = useState(false);
  const [resumen, setResumen] = useState<ResumenCaja | null>(null);
  const [contado, setContado] = useState<Record<string, string>>({});
  const [observacion, setObservacion] = useState('');
  const [error, setError] = useState<string | null>(null);
  if (!puede) return null;

  async function abrir() {
    setError(null);
    setObservacion('');
    setAbierto(true);
    const r = await ejecutar<{ data: ResumenCaja }>('academico/pagos/caja-diaria/resumen', 'GET');
    if (!r.ok) {
      setError(r.error.message);
      return;
    }
    setResumen(r.datos.data);
    setContado(Object.fromEntries(r.datos.data.por_metodo.map((m) => [m.codigo, m.total])));
  }

  const diferenciaCentimos = resumen
    ? resumen.por_metodo.reduce((t, m) => t + Math.round(Number(contado[m.codigo] || 0) * 100) - Math.round(Number(m.total) * 100), 0)
    : 0;

  async function cerrar() {
    if (!resumen) return;
    setOcupado(true);
    setError(null);
    const r = await ejecutar('academico/pagos/caja-diaria/cierre', 'POST', { fecha: resumen.fecha, declarado: contado, observacion: observacion.trim() || null });
    setOcupado(false);
    if (!r.ok) {
      setError(r.error.message);
      return;
    }
    mostrar('exito', `Caja del ${resumen.fecha} cerrada.`);
    setAbierto(false);
  }

  return (
    <>
      <Boton variante="secundario" onClick={() => void abrir()}>Cerrar caja</Boton>
      <Dialogo abierto={abierto} lado="derecha" titulo="Cierre de caja" descripcion="Cuenta lo recibido por método y compáralo con el libro de pagos. El cierre no se puede modificar." onCerrar={() => setAbierto(false)} bloqueado={ocupado}>
        {error ? <Alerta tipo="error">{error}</Alerta> : null}
        {resumen === null ? (
          error ? null : <p className="text-sm text-panel-muted">Cargando la caja del día…</p>
        ) : resumen.cierre ? (
          <Alerta tipo="aviso">
            La caja del {resumen.fecha} ya fue cerrada{resumen.cierre.cerrado_por ? ` por ${resumen.cierre.cerrado_por}` : ''}: esperado {moneda(resumen.cierre.total_esperado)}, contado {moneda(resumen.cierre.total_declarado)}, diferencia {moneda(resumen.cierre.diferencia)}.
          </Alerta>
        ) : (
          <div className="space-y-4">
            <p className="text-sm text-panel-text">
              {resumen.fecha}: {resumen.cantidad} pago(s) por {moneda(resumen.cobrado)}, {resumen.cantidad_reversos} reverso(s) por {moneda(resumen.reversado)}. <strong>Neto {moneda(resumen.total)}</strong>.
            </p>
            {resumen.pendientes_por_confirmar > 0 ? (
              <Alerta tipo="aviso">Hay {resumen.pendientes_por_confirmar} pago(s) pendiente(s) de confirmar de este día: no entran en el cierre.</Alerta>
            ) : null}
            {resumen.por_metodo.map((m) => (
              <Campo
                key={m.codigo}
                etiqueta={`${m.metodo} contado (esperado ${moneda(m.total)})`}
                inputMode="decimal"
                value={contado[m.codigo] ?? ''}
                onChange={(e) => setContado((c) => ({ ...c, [m.codigo]: normalizarImporte(e.target.value) }))}
              />
            ))}
            <p className={`text-sm font-medium ${diferenciaCentimos === 0 ? 'text-panel-text' : 'text-estado-error'}`}>
              Diferencia: {moneda((diferenciaCentimos / 100).toFixed(2))}
            </p>
            <Campo etiqueta={diferenciaCentimos === 0 ? 'Observación (opcional)' : 'Motivo de la diferencia (obligatorio)'} value={observacion} maxLength={500} onChange={(e) => setObservacion(e.target.value)} />
            <Boton cargando={ocupado} onClick={() => void cerrar()}>Cerrar la caja</Boton>
          </div>
        )}
      </Dialogo>
    </>
  );
}

/** Exporta las obligaciones a Excel (solo con `pagos.exportar`) conservando los filtros activos. */
export function BotonExportarPagos({ filtros = '' }: { filtros?: string }) {
  const { descargar, ejecutar } = useOperacionPanel();
  const { mostrar } = useAvisos();
  const puedeExportar = usePuede('pagos.exportar');
  const puedeVer = usePuede('pagos.ver');
  const [ocupado, setOcupado] = useState(false);
  const [cajaOcupada, setCajaOcupada] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [aviso, setAviso] = useState<string | null>(null);
  if (!puedeExportar && !puedeVer) return null;

  async function exportar() {
    setOcupado(true);
    setError(null);
    setAviso(null);
    const r = await descargar(`academico/pagos/exportar${filtros}`);
    setOcupado(false);
    if (!r.ok) {
      setError(r.error.message);
      return;
    }
    if (r.aviso) setAviso(r.aviso);
    else mostrar('exito', 'Exportación de pagos generada.');
  }

  async function cajaPdf() {
    setCajaOcupada(true);
    const r = await descargar('academico/pagos/caja-diaria/pdf');
    setCajaOcupada(false);
    mostrar(r.ok ? 'exito' : 'error', r.ok ? 'Reporte de Caja Diaria descargado.' : r.error.message);
  }

  async function copiarResumenCaja() {
    const r = await ejecutar<{ data: { fecha: string; cantidad: number; total: string; por_metodo: { metodo: string; cantidad: number; total: string }[] } }>('academico/pagos/caja-diaria/resumen', 'GET');
    if (!r.ok) {
      mostrar('error', r.error.message);
      return;
    }
    const d = r.datos.data;
    const metodos = d.por_metodo.map((m) => `• ${m.metodo}: S/ ${m.total} (${m.cantidad})`).join('\n');
    const texto = `📊 *Cierre de Caja Horus (${d.fecha})*\nOperaciones confirmadas: ${d.cantidad}\n${metodos ? `${metodos}\n` : ''}*Total recaudado: S/ ${d.total}*`;
    const ok = await copiarAlPortapapeles(texto);
    mostrar(ok ? 'exito' : 'error', ok ? 'Resumen de caja copiado para WhatsApp.' : 'No se pudo copiar.');
  }

  return (
    <div className="space-y-2">
      {error ? <Alerta tipo="error">{error}</Alerta> : null}
      {aviso ? <Alerta tipo="aviso">{aviso}</Alerta> : null}
      <div className="flex flex-wrap gap-2">
        {puedeVer ? (
          <>
            <Boton variante="secundario" icono={<FileText className="h-4 w-4" aria-hidden="true" />} cargando={cajaOcupada} onClick={() => void cajaPdf()}>
              Caja Hoy (PDF)
            </Boton>
            <Boton variante="fantasma" icono={<Copy className="h-4 w-4" aria-hidden="true" />} onClick={() => void copiarResumenCaja()}>
              Copiar resumen caja
            </Boton>
            <BotonCerrarCaja />
          </>
        ) : null}
        {puedeExportar ? <Boton variante="secundario" cargando={ocupado} onClick={() => void exportar()}>Exportar Excel</Boton> : null}
      </div>
    </div>
  );
}

/** Descarga el recibo / estado de cuenta en PDF de la obligación (`pagos.ver`). */
export function BotonReciboPdf({ identificador }: { identificador: string }) {
  const { descargar } = useOperacionPanel();
  const { mostrar } = useAvisos();
  const puede = usePuede('pagos.ver');
  const [ocupado, setOcupado] = useState(false);
  if (!puede) return null;

  async function bajar() {
    setOcupado(true);
    const r = await descargar(`academico/pagos/obligaciones/${identificador}/recibo`, 'GET');
    setOcupado(false);
    if (!r.ok) mostrar('error', r.error.message);
  }

  return (
    <Boton variante="secundario" icono={<FileText className="h-4 w-4" aria-hidden="true" />} cargando={ocupado} onClick={() => void bajar()}>
      Recibo PDF
    </Boton>
  );
}
