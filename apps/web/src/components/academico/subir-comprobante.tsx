'use client';

import { Upload } from 'lucide-react';
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
import { Campo, CampoSeleccion } from '@/components/shared/ui/campo';
import { Dialogo } from '@/components/shared/ui/dialogo';
import { SelectorRemoto, type OpcionRemota } from '@/components/shared/ui/selector-remoto';
import type { ImagenRepetida, MatriculaResumen, ObligacionDetalle } from '@/features/academico/tipos';
import { fecha, moneda } from '@/lib/panel/formato';

const IMPORTE = /^(0|[1-9]\d{0,7})(\.\d{1,2})?$/;
const MAX_CAPTURA = 3 * 1024 * 1024;
const TIPOS_CAPTURA = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'];
const hoyLima = () => new Intl.DateTimeFormat('en-CA', { timeZone: 'America/Lima' }).format(new Date());

const esquema = z.object({
  metodo: z.string().min(1, 'Elige cómo pagó la persona.'),
  monto: z.string().regex(IMPORTE, 'Importe válido, hasta 2 decimales.').refine((v) => Number(v) > 0, 'Debe ser mayor que cero.'),
  fecha_pago: z.string().min(1, 'Indica la fecha del pago.'),
  referencia: z.string().max(80, 'Hasta 80 caracteres.'),
});

/** Cifras de cobro de la matrícula elegida, en céntimos (evita errores de coma flotante al restar). */
interface Cuenta {
  total: number;
  pagado: number;
  porConfirmar: number;
  /** Lo que aún se puede registrar: total − confirmado − por confirmar. */
  disponible: number;
  /** `true` si la obligación todavía no existe: Laravel la crea al guardar con el precio del curso. */
  nueva: boolean;
}

const centimos = (valor: string | null | undefined) => Math.round(Number(valor ?? 0) * 100);
const soles = (c: number) => moneda((c / 100).toFixed(2));

function validarCaptura(archivo: File | null): string | null {
  if (archivo === null) return 'Adjunta el comprobante de pago.';
  if (!TIPOS_CAPTURA.includes(archivo.type)) return 'Sube una captura en JPG, PNG o WebP, o un PDF.';
  if (archivo.size > MAX_CAPTURA) return 'El comprobante no puede pasar de 3 MB.';
  return null;
}

/**
 * «Subir comprobante»: el flujo natural de Pagos. Se busca la matrícula, se ve cuánto debe, se indica lo que la persona pagó y se
 * sube su constancia. No hay que crear nada antes: si la matrícula aún no tiene obligación, Laravel la crea con el precio del curso.
 * Todo importe y regla se valida de nuevo en Laravel; aquí solo se muestra el saldo restante como ayuda.
 */
export function BotonSubirComprobante() {
  const puede = usePuede('pagos.registrar');
  const [abierto, setAbierto] = useState(false);
  const [ocupado, setOcupado] = useState(false);
  if (!puede) return null;

  return (
    <>
      <Boton icono={<Upload className="h-4 w-4" aria-hidden="true" />} onClick={() => setAbierto(true)}>Subir comprobante</Boton>
      <Dialogo
        abierto={abierto}
        titulo="Subir comprobante de pago"
        descripcion="Busca al estudiante, indica lo que pagó y adjunta su comprobante. El saldo pendiente se calcula solo."
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
  const [confirmar, setConfirmar] = useState(false);
  const [alertaReferencia, setAlertaReferencia] = useState<string | null>(null);
  const [resultado, setResultado] = useState<{ obligacion: string; restante: number } | null>(null);
  const [imagenRepetida, setImagenRepetida] = useState<ImagenRepetida | null>(null);
  const encontradas = useRef(new Map<number, MatriculaResumen>());
  // Una intención = una clave: si la red falla y se reintenta, Laravel devuelve el pago original sin duplicarlo.
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

  // Al elegir la matrícula se lee su obligación: si existe, sus cifras; si no, el precio del curso (se creará al guardar).
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
        setCuenta({ total, pagado, porConfirmar, disponible: Math.max(0, total - pagado - porConfirmar), nueva: false });
        return;
      }
      const precio = centimos(encontradas.current.get(matricula.id)?.curso?.precio);
      setCuenta({ total: precio, pagado: 0, porConfirmar: 0, disponible: precio, nueva: true });
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
      const sinMatricula = matricula === null ? 'Selecciona la matrícula del estudiante.' : undefined;
      const sinCaptura = validarCaptura(captura) ?? undefined;
      if (sinMatricula || sinCaptura || matricula === null || captura === null) {
        setErrorMatricula(sinMatricula);
        setErrorCaptura(sinCaptura);
        return { ok: false as const, estado: 422, error: { message: 'Revisa los datos del formulario.', code: 'cliente' } };
      }
      const registro = await ejecutar<{ message: string; transaccion: string; data: ObligacionDetalle }>(`academico/matriculas/${matricula.id}/pagos`, 'POST', {
        metodo: d.metodo, monto: d.monto, fecha_pago: d.fecha_pago, referencia: d.referencia === '' ? null : d.referencia, _clave: claveIntento.current,
      });
      if (!registro.ok) return registro;

      const formulario = new FormData();
      formulario.append('imagen', captura);
      const subida = await subir<{ message: string; advertencias?: { imagen_repetida: ImagenRepetida | null } }>(`academico/pagos/transacciones/${registro.datos.transaccion}/comprobante`, formulario);
      if (!subida.ok) {
        router.refresh();
        return { ok: false as const, estado: subida.estado, error: { message: `El pago se registró, pero no se pudo adjuntar el comprobante: ${subida.error.message} Vuelve a intentarlo: no se duplicará el pago.`, code: 'captura' } };
      }
      // La misma captura ya respalda otro pago vigente: se avisa y NO se confirma sola aunque se haya marcado; decide una persona.
      const repetida = subida.datos.advertencias?.imagen_repetida ?? null;
      setImagenRepetida(repetida);
      if (confirmar && puedeConfirmar && repetida === null) {
        const confirmacion = await ejecutar<{ message: string }>(`academico/pagos/transacciones/${registro.datos.transaccion}/confirmar`, 'POST', {});
        if (!confirmacion.ok) {
          router.refresh();
          return { ok: false as const, estado: confirmacion.estado, error: { message: `El pago y el comprobante se guardaron, pero no se pudo confirmar: ${confirmacion.error.message}`, code: 'confirmacion' } };
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

  // Aviso (no bloquea) si el código de operación ya se usó en otro pago.
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

  const montoEscrito = f.form.watch('monto') ?? '';
  const montoValido = IMPORTE.test(montoEscrito) && Number(montoEscrito) > 0;
  const restante = cuenta === null || !montoValido ? null : cuenta.disponible - centimos(montoEscrito);

  if (resultado) {
    const pagoTodo = resultado.restante <= 0;
    return (
      <div className="space-y-4">
        <Alerta tipo={pagoTodo ? 'exito' : 'aviso'}>
          {pagoTodo ? 'Pagó todo. La matrícula no tiene saldo pendiente.' : `Pagó una parte. Debe ${soles(resultado.restante)}.`}
        </Alerta>
        {imagenRepetida ? (
          <Alerta tipo="error">
            Esta misma captura ya respalda un pago de la matrícula #{imagenRepetida.matricula_id} ({fecha(imagenRepetida.fecha_pago)}). El pago quedó «por confirmar» y no se confirmó
            automáticamente. Antes de confirmarlo, comprueba en tu app de Yape que esta operación existe.
          </Alerta>
        ) : null}
        <div className="flex flex-wrap items-center justify-end gap-2">
          <Link href={`/admin/academico/pagos/${resultado.obligacion}`} className="text-sm font-semibold text-academico-700 hover:underline sm:self-center">Ver pagos de esta matrícula</Link>
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
            <p className="text-estado-error">No se pudo consultar lo pagado. Vuelve a elegir la matrícula.</p>
          ) : cuenta === null ? (
            <p className="text-panel-muted">Consultando lo pagado…</p>
          ) : (
            <dl className="grid grid-cols-2 gap-x-4 gap-y-1 sm:grid-cols-4">
              <div><dt className="text-xs text-panel-muted">Total</dt><dd className="font-semibold text-panel-text">{soles(cuenta.total)}</dd></div>
              <div><dt className="text-xs text-panel-muted">Pagado</dt><dd className="font-semibold text-panel-text">{soles(cuenta.pagado)}</dd></div>
              <div><dt className="text-xs text-panel-muted">Por confirmar</dt><dd className="font-semibold text-panel-text">{soles(cuenta.porConfirmar)}</dd></div>
              <div><dt className="text-xs text-panel-muted">Puede registrar</dt><dd className="font-semibold text-panel-text">{soles(cuenta.disponible)}</dd></div>
            </dl>
          )}
          {cuenta?.nueva ? <p className="mt-2 text-xs text-panel-muted">Es el primer pago: se tomará el precio del curso como total.</p> : null}
          {cuenta !== null && cuenta.disponible <= 0 ? <p className="mt-2 text-xs text-estado-error">Esta matrícula no tiene saldo disponible para registrar.</p> : null}
        </div>
      ) : null}
      <div className="grid gap-4 sm:grid-cols-2">
        <Campo etiqueta="Importe pagado (S/)" inputMode="decimal" {...f.registro('monto')} error={f.error('monto')} />
        <Campo etiqueta="Fecha del pago" type="date" {...f.registro('fecha_pago')} error={f.error('fecha_pago')} />
      </div>
      {restante !== null ? (
        <p className={`text-sm font-semibold ${restante < 0 ? 'text-estado-error' : 'text-panel-text'}`} aria-live="polite">
          {restante < 0 ? `El importe supera el saldo disponible por ${soles(-restante)}.` : restante === 0 ? 'Saldo restante: S/ 0.00 (queda pagada).' : `Saldo restante: ${soles(restante)}`}
        </p>
      ) : null}
      <div className="grid gap-4 sm:grid-cols-2">
        <CampoSeleccion etiqueta="Método de pago" {...f.registro('metodo')} error={f.error('metodo')} opciones={Object.fromEntries(metodos.map((m) => [m.codigo, m.nombre]))} />
        <Campo etiqueta="Código de operación (opcional)" {...f.registro('referencia')} error={f.error('referencia')} />
      </div>
      {alertaReferencia && (refActual ?? '').trim().length >= 3 ? <Alerta tipo="aviso">{alertaReferencia}</Alerta> : null}
      <Campo
        etiqueta="Comprobante de pago"
        type="file"
        accept={TIPOS_CAPTURA.join(',')}
        onChange={(e) => setCaptura(e.target.files?.[0] ?? null)}
        error={errorCaptura}
        ayuda="JPG, PNG, WebP o PDF de hasta 3 MB. Es obligatorio. Se rechaza un PDF con scripts o archivos incrustados."
      />
      {puedeConfirmar ? (
        <div className="space-y-1">
          <label className="flex items-center gap-2 text-sm text-panel-text">
            <input type="checkbox" checked={confirmar} onChange={(e) => setConfirmar(e.target.checked)} className="h-4 w-4 rounded border-panel-border accent-academico-700" />
            Confirmar el pago ahora
          </label>
          <p className="text-xs text-panel-muted">Marca esto solo si ya comprobaste en tu app de Yape que la operación existe: una captura puede estar editada.</p>
        </div>
      ) : (
        <p className="text-xs text-panel-muted">El pago quedará «por confirmar» hasta que lo revise una persona autorizada.</p>
      )}
      <div className="flex flex-col-reverse gap-2 border-t border-panel-border pt-4 sm:flex-row sm:justify-end">
        <Boton variante="secundario" onClick={onCerrar} disabled={f.guardando}>Cancelar</Boton>
        <Boton type="submit" cargando={f.guardando} disabled={cuenta !== null && cuenta.disponible <= 0}>Guardar pago</Boton>
      </div>
    </form>
  );
}
