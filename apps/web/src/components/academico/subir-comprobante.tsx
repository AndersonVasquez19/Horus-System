'use client';

import { Wallet } from 'lucide-react';
import Link from 'next/link';
import { useRouter } from 'next/navigation';
import { useCallback, useEffect, useRef, useState } from 'react';
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
import { SelectorRemoto, type OpcionRemota } from '@/components/shared/ui/selector-remoto';
import type { ImagenRepetida, MatriculaResumen, ObligacionDetalle } from '@/features/academico/tipos';
import { ACEPTA_CAPTURA, IMPORTE, importeValido, normalizarImporte, validarCaptura } from '@/lib/panel/captura';
import { fecha, hoyLima, moneda } from '@/lib/panel/formato';

const METODOS_CAPTURA_OBLIGATORIA = new Set(['yape', 'plin']);

const esquema = z.object({
  metodo: z.string().min(1, 'Elige cómo pagó el estudiante.'),
  monto: z.string().regex(IMPORTE, 'Importe válido, hasta 2 decimales (por ejemplo 50.00).').refine((v) => Number(v) > 0, 'Debe ser mayor que cero.'),
  fecha_pago: z.string().min(1, 'Indica la fecha del pago.'),
  referencia: z.string().max(80, 'Hasta 80 caracteres.'),
});

/** Cifras simplificadas de la matrícula elegida, en céntimos. */
interface Cuenta {
  total: number;
  pagado: number;
  porConfirmar: number;
  faltaPagar: number;
  nueva: boolean;
}

const centimos = (valor: string | null | undefined) => Math.round(Number(valor ?? 0) * 100);
const soles = (c: number) => moneda((c / 100).toFixed(2));

/**
 * «Registrar pago»: permite cobrar abonos parciales o completos de cualquier matrícula pendiente.
 * Muestra solo 3 cifras claras (Precio del curso, Ya pagado, Falta pagar).
 * Comprobante obligatorio para Yape/Plin, opcional para efectivo/transferencia/depósito.
 */
export function BotonSubirComprobante() {
  const puede = usePuede('pagos.registrar');
  const [abierto, setAbierto] = useState(false);
  const [ocupado, setOcupado] = useState(false);
  if (!puede) return null;

  return (
    <>
      <Boton icono={<Wallet className="h-4 w-4" aria-hidden="true" />} onClick={() => setAbierto(true)}>Registrar pago</Boton>
      <Dialogo
        abierto={abierto}
        titulo="Registrar pago"
        descripcion="Busca al estudiante, indica cuánto pagó y guarda el abono. El saldo restante se calcula automáticamente."
        onCerrar={() => setAbierto(false)}
        bloqueado={ocupado}
      >
        {abierto ? <Formulario onOcupado={setOcupado} onCerrar={() => setAbierto(false)} /> : null}
      </Dialogo>
    </>
  );
}

function Formulario({ onOcupado, onCerrar }: { onOcupado: (b: boolean) => void; onCerrar: () => void }) {
  const router = useRouter();
  const { mostrar } = useAvisos();
  const { ejecutar, subir } = useOperacionPanel();
  const puedeConfirmar = usePuede('pagos.confirmar');
  const [matricula, setMatricula] = useState<OpcionRemota | null>(null);
  const [errorMatricula, setErrorMatricula] = useState<string | undefined>();
  const [cuenta, setCuenta] = useState<Cuenta | null>(null);
  const [errorCuenta, setErrorCuenta] = useState(false);
  const [metodos, setMetodos] = useState<{ codigo: string; nombre: string }[]>([{ codigo: 'yape', nombre: 'Yape' }]);
  const [captura, setCaptura] = useState<File | null>(null);
  const [errorCaptura, setErrorCaptura] = useState<string | undefined>();
  const [confirmar, setConfirmar] = useState(puedeConfirmar);
  const [alertaReferencia, setAlertaReferencia] = useState<string | null>(null);
  const [resultado, setResultado] = useState<{ obligacion: string; restante: number } | null>(null);
  const [imagenRepetida, setImagenRepetida] = useState<ImagenRepetida | null>(null);
  const encontradas = useRef(new Map<number, MatriculaResumen>());
  const claveIntento = useRef(crypto.randomUUID());

  const buscarMatriculas = useCallback(
    async (texto: string): Promise<OpcionRemota[] | null> => {
      const parametros = new URLSearchParams({ estado: 'pendiente', per_page: '10' });
      if (texto) parametros.set('busqueda', texto);
      const r = await ejecutar<{ data: MatriculaResumen[] }>(`academico/matriculas?${parametros}`);
      if (!r.ok) return null;
      return r.datos.data
        .filter((m) => !m.eliminado && m.estudiante !== null)
        .map((m) => {
          encontradas.current.set(m.id, m);
          const e = m.estudiante;
          return { id: m.id, texto: `${e?.nombre ?? ''} · DNI ${e?.dni ?? ''} · ${m.convocatoria?.codigo ?? m.curso?.codigo ?? 'Matrícula'}` };
        });
    },
    [ejecutar],
  );

  useEffect(() => {
    if (matricula === null) return;
    let activo = true;
    void ejecutar<{ data: ObligacionDetalle | null }>(`academico/matriculas/${matricula.id}/obligacion`).then((r) => {
      if (!activo) return;
      if (!r.ok) {
        setErrorCuenta(true);
        return;
      }
      const o = r.datos.data;
      if (o !== null) {
        const total = centimos(o.monto_total);
        const pagado = centimos(o.pagado);
        const porConfirmar = centimos(o.pendiente_confirmar);
        setCuenta({ total, pagado, porConfirmar, faltaPagar: Math.max(0, total - pagado - porConfirmar), nueva: false });
        return;
      }
      const precio = centimos(encontradas.current.get(matricula.id)?.curso?.precio);
      setCuenta({ total: precio, pagado: 0, porConfirmar: 0, faltaPagar: precio, nueva: true });
    });
    return () => {
      activo = false;
    };
  }, [ejecutar, matricula]);

  useEffect(() => {
    let activo = true;
    void ejecutar<{ data: { codigo: string; nombre: string }[] }>('academico/pagos/metodos').then((r) => {
      if (activo && r.ok && r.datos.data.length > 0) setMetodos(r.datos.data);
    });
    return () => {
      activo = false;
    };
  }, [ejecutar]);

  const f = useFormularioPanel({
    esquema,
    valores: { metodo: 'yape', monto: '', fecha_pago: hoyLima(), referencia: '' },
    onOcupado,
    enviar: async (d) => {
      setErrorMatricula(undefined);
      setErrorCaptura(undefined);
      const requiereCaptura = METODOS_CAPTURA_OBLIGATORIA.has(d.metodo);
      const sinMatricula = matricula === null ? 'Selecciona la matrícula del estudiante.' : undefined;
      const errArchivo = requiereCaptura
        ? (validarCaptura(captura) ?? undefined)
        : captura !== null
          ? (validarCaptura(captura) ?? undefined)
          : undefined;
      if (sinMatricula || errArchivo || matricula === null) {
        setErrorMatricula(sinMatricula);
        setErrorCaptura(errArchivo);
        return { ok: false as const, estado: 422, error: { message: 'Revisa los datos del formulario.', code: 'cliente' } };
      }

      const registro = await ejecutar<{ message: string; transaccion: string; data: ObligacionDetalle }>(`academico/matriculas/${matricula.id}/pagos`, 'POST', {
        metodo: d.metodo,
        monto: d.monto,
        fecha_pago: d.fecha_pago,
        referencia: d.referencia === '' ? null : d.referencia,
        _clave: claveIntento.current,
      });
      if (!registro.ok) return registro;

      let repetida: ImagenRepetida | null = null;
      if (captura !== null) {
        const formulario = new FormData();
        formulario.append('imagen', captura);
        const subida = await subir<{ message: string; advertencias?: { imagen_repetida: ImagenRepetida | null } }>(`academico/pagos/transacciones/${registro.datos.transaccion}/comprobante`, formulario);
        if (!subida.ok) {
          router.refresh();
          return { ok: false as const, estado: subida.estado, error: { message: `El pago se registró, pero no se pudo adjuntar el comprobante: ${subida.error.message}`, code: 'captura' } };
        }
        repetida = subida.datos.advertencias?.imagen_repetida ?? null;
        setImagenRepetida(repetida);
      }

      if (confirmar && puedeConfirmar && repetida === null) {
        const confirmacion = await ejecutar<{ message: string }>(`academico/pagos/transacciones/${registro.datos.transaccion}/confirmar`, 'POST', {});
        if (!confirmacion.ok) {
          router.refresh();
          return { ok: false as const, estado: confirmacion.estado, error: { message: `El pago se guardó, pero no se pudo confirmar: ${confirmacion.error.message}`, code: 'confirmacion' } };
        }
      }
      return registro;
    },
    onExito: (r) => {
      const o = r.data;
      setResultado({ obligacion: o.identificador, restante: Math.max(0, centimos(o.saldo) - centimos(o.pendiente_confirmar)) });
      mostrar('exito', r.message);
      router.refresh();
    },
  });

  const metodoActual = f.form.watch('metodo') ?? 'yape';
  const capturaObligatoria = METODOS_CAPTURA_OBLIGATORIA.has(metodoActual);

  const refActual = f.form.watch('referencia');
  useEffect(() => {
    const limpio = (refActual ?? '').trim();
    if (limpio.length < 3) return;
    const timer = setTimeout(async () => {
      const r = await ejecutar<{ data: { duplicada: boolean; coincidencias: { matricula_id: number; fecha_pago: string }[] } }>(
        `academico/pagos/verificar-referencia?referencia=${encodeURIComponent(limpio)}`,
      );
      const primera = r.ok && r.datos.data.duplicada ? r.datos.data.coincidencias[0] : undefined;
      setAlertaReferencia(r.ok && r.datos.data.duplicada ? (primera ? `Advertencia: este código de operación ya se registró en la matrícula #${primera.matricula_id} (${primera.fecha_pago}).` : 'Advertencia: este código de operación ya fue registrado antes.') : null);
    }, 350);
    return () => clearTimeout(timer);
  }, [ejecutar, refActual]);

  const montoEscrito = normalizarImporte(f.form.watch('monto') ?? '');
  const restante = cuenta === null || !importeValido(montoEscrito) ? null : cuenta.faltaPagar - centimos(montoEscrito);

  if (resultado) {
    const pagoTodo = resultado.restante <= 0;
    return (
      <div className="space-y-4">
        <Alerta tipo={pagoTodo ? 'exito' : 'aviso'}>
          {pagoTodo ? 'Pagado completo. Esta matrícula ya no tiene deuda.' : `Abono registrado. Aún debe ${soles(resultado.restante)}.`}
        </Alerta>
        {imagenRepetida ? (
          <Alerta tipo="error">
            Esta misma captura ya respalda un pago de la matrícula #{imagenRepetida.matricula_id} ({fecha(imagenRepetida.fecha_pago)}). El pago quedó «por confirmar» para revisión.
          </Alerta>
        ) : null}
        <div className="flex flex-wrap items-center justify-end gap-2">
          <Link href={`/admin/academico/pagos/${resultado.obligacion}`} className="text-sm font-semibold text-academico-700 hover:underline sm:self-center">Ver detalle de pagos</Link>
          <Boton onClick={onCerrar}>Cerrar</Boton>
        </div>
      </div>
    );
  }

  return (
    <form onSubmit={f.enviar} noValidate className="space-y-4">
      {f.errorGeneral ? <Alerta tipo="error">{f.errorGeneral}</Alerta> : null}
      <SelectorRemoto
        etiqueta="Estudiante o matrícula"
        valor={matricula}
        onCambiar={(m) => {
          setMatricula(m);
          setCuenta(null);
          setErrorCuenta(false);
        }}
        buscar={buscarMatriculas}
        marcador="Busca por DNI, nombres, curso o edición"
        ayuda="Solo aparecen matrículas con saldo por cobrar."
        error={errorMatricula}
      />
      {matricula !== null ? (
        <div className="rounded-xl border border-panel-border bg-panel-bg px-4 py-3 text-sm" aria-live="polite">
          {errorCuenta ? (
            <p className="text-estado-error">No se pudo consultar el saldo. Vuelve a elegir la matrícula.</p>
          ) : cuenta === null ? (
            <p className="text-panel-muted">Consultando saldo…</p>
          ) : (
            <dl className="grid grid-cols-3 gap-4">
              <div>
                <dt className="text-xs text-panel-muted">Precio del curso</dt>
                <dd className="font-semibold tabular-nums text-panel-text">{soles(cuenta.total)}</dd>
              </div>
              <div>
                <dt className="text-xs text-panel-muted">Ya pagado</dt>
                <dd className="font-semibold tabular-nums text-emerald-700">{soles(cuenta.pagado)}</dd>
              </div>
              <div>
                <dt className="text-xs text-panel-muted">Falta pagar</dt>
                <dd className={`font-semibold tabular-nums ${cuenta.faltaPagar > 0 ? 'text-amber-700' : 'text-emerald-700'}`}>
                  {soles(cuenta.faltaPagar)}
                </dd>
              </div>
            </dl>
          )}
          {cuenta !== null && cuenta.porConfirmar > 0 ? (
            <p className="mt-2 text-xs text-panel-muted">Hay {soles(cuenta.porConfirmar)} en un abono pendiente de confirmación.</p>
          ) : null}
          {cuenta !== null && cuenta.total <= 0 ? (
            <p className="mt-2 text-xs text-estado-error">El curso no tiene precio definido (S/ 0.00). Define su precio en Cursos primero.</p>
          ) : cuenta !== null && cuenta.faltaPagar <= 0 ? (
            <p className="mt-2 text-xs text-emerald-700">Esta matrícula ya está completamente pagada.</p>
          ) : null}
        </div>
      ) : null}
      <div className="grid gap-4 sm:grid-cols-2">
        <Campo
          etiqueta="Monto a pagar ahora (S/)"
          inputMode="decimal"
          required
          ayuda={cuenta && cuenta.faltaPagar > 0 ? `Máximo ${soles(cuenta.faltaPagar)}` : 'Ej. 50.00'}
          {...f.registro('monto', { setValueAs: normalizarImporte })}
          error={f.error('monto')}
        />
        <Campo etiqueta="Fecha del pago" type="date" {...f.registro('fecha_pago')} error={f.error('fecha_pago')} />
      </div>
      {restante !== null ? (
        <p className={`text-sm font-semibold ${restante < 0 ? 'text-estado-error' : restante === 0 ? 'text-emerald-700' : 'text-amber-700'}`} aria-live="polite">
          {restante < 0
            ? `El monto supera lo que falta pagar por ${soles(-restante)}.`
            : restante === 0
              ? 'Con este pago quedará Pagado completo (S/ 0.00 pendiente).'
              : `Después de este abono quedará pendiente: ${soles(restante)}`}
        </p>
      ) : null}
      <div className="grid gap-4 sm:grid-cols-2">
        <CampoSeleccion etiqueta="Método de pago" {...f.registro('metodo')} error={f.error('metodo')} opciones={Object.fromEntries(metodos.map((m) => [m.codigo, m.nombre]))} />
        <Campo etiqueta="Código de operación (opcional)" {...f.registro('referencia')} error={f.error('referencia')} />
      </div>
      {alertaReferencia && (refActual ?? '').trim().length >= 3 ? <Alerta tipo="aviso">{alertaReferencia}</Alerta> : null}
      <CampoArchivo
        etiqueta={capturaObligatoria ? 'Comprobante de pago (obligatorio)' : 'Comprobante de pago (opcional)'}
        requerido={capturaObligatoria}
        accept={ACEPTA_CAPTURA}
        onArchivo={setCaptura}
        error={errorCaptura}
        ayuda={
          capturaObligatoria
            ? 'Obligatorio para Yape y Plin. JPG, PNG, WebP o PDF de hasta 3 MB.'
            : 'Opcional para efectivo, transferencia o depósito. JPG, PNG, WebP o PDF de hasta 3 MB.'
        }
      />
      {puedeConfirmar ? (
        <Casilla
          etiqueta="Confirmar el pago inmediatamente"
          ayuda="Desmárcalo solo si deseas dejarlo en revisión en la pestaña Comprobantes."
          checked={confirmar}
          onChange={(e) => setConfirmar(e.target.checked)}
        />
      ) : (
        <p className="text-xs text-panel-muted">El pago quedará «por confirmar» hasta que lo apruebe un administrador.</p>
      )}
      <div className="pie-acciones">
        <Boton variante="secundario" onClick={onCerrar} disabled={f.guardando}>Cancelar</Boton>
        <Boton type="submit" cargando={f.guardando} disabled={cuenta !== null && cuenta.faltaPagar <= 0}>Guardar pago</Boton>
      </div>
    </form>
  );
}
