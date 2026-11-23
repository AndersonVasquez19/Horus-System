'use client';

import { useRouter } from 'next/navigation';
import { useCallback, useEffect, useRef, useState, type FormEvent } from 'react';
import { usePuede } from '@/components/shared/sesion-panel-contexto';
import { useAdvertirCambios } from '@/components/shared/use-advertir-cambios';
import { useOperacionPanel } from '@/components/shared/use-operacion-panel';
import { Alerta } from '@/components/shared/ui/alerta';
import { useAvisos } from '@/components/shared/ui/avisos';
import { Boton } from '@/components/shared/ui/boton';
import { Campo, CampoArchivo, CampoArea, CampoSeleccion } from '@/components/shared/ui/campo';
import { SelectorRemoto, type OpcionRemota } from '@/components/shared/ui/selector-remoto';
import type { ConvocatoriaResumen, CursoResumen, MatriculaDetalle, OpcionSelector } from '@/features/academico/tipos';
import { ACEPTA_CAPTURA, validarCaptura } from '@/lib/panel/captura';
import { errorDeCampo } from '@/lib/panel/cliente';
import { ETIQUETAS, hoyLima, moneda } from '@/lib/panel/formato';
import type { ErrorPanel } from '@/lib/panel/tipos';

const DETALLE = '/admin/academico/matriculas';
const METODOS_CAPTURA_OBLIGATORIA = new Set(['yape', 'plin']);

interface FormularioMatriculaProps {
  /** Sin matrícula: registro. Con matrícula: edición. */
  matricula?: MatriculaDetalle;
  /** Preselección del registro (p. ej. desde la ficha del estudiante). */
  estudianteInicial?: OpcionRemota | null;
  /** Preselección del curso (p. ej. desde su ficha), solo si es elegible. */
  cursoInicial?: OpcionRemota | null;
  /** Fecha propuesta para un registro nuevo (hoy en Lima, calculada por la página en el servidor). */
  fechaInicial?: string;
}

/**
 * Registro y edición de matrículas con el mismo diseño limpio de los demás formularios del panel.
 * Al crear una matrícula, el selector «Estado» ofrece:
 *  - Pendiente (genera deuda total en Pagos y deudores)
 *  - Abono parcial (registra un adelanto y deja el saldo pendiente)
 *  - Pagada (registra el 100 % del curso y pasa a Pagos completos)
 */
export function FormularioMatricula({ matricula, estudianteInicial = null, cursoInicial = null, fechaInicial = '' }: FormularioMatriculaProps) {
  const router = useRouter();
  const { mostrar } = useAvisos();
  const { ejecutar, subir } = useOperacionPanel();
  const puedeAnular = usePuede('matriculas.anular');
  const puedeConPago = [usePuede('matriculas.crear'), usePuede('pagos.planificar'), usePuede('pagos.registrar'), usePuede('pagos.confirmar')].every(Boolean);
  const contexto = matricula ? 'editar' : 'crear';

  const [estudiante, setEstudiante] = useState<OpcionRemota | null>(
    matricula?.estudiante ? { id: matricula.estudiante.id, texto: `${matricula.estudiante.dni} · ${matricula.estudiante.nombre}` } : estudianteInicial,
  );
  const [curso, setCurso] = useState<OpcionRemota | null>(
    matricula?.curso ? { id: matricula.curso.id, texto: `${matricula.curso.codigo} · ${matricula.curso.nombre}` } : cursoInicial,
  );
  const [precioCurso, setPrecioCurso] = useState<number | null>(null);
  const [fechaMatricula, setFechaMatricula] = useState(matricula?.fecha ?? fechaInicial);
  const [estado, setEstado] = useState<string>(matricula?.estado ?? 'pendiente');
  const [motivo, setMotivo] = useState('');
  const [observaciones, setObservaciones] = useState(matricula?.observaciones ?? '');
  const [error, setError] = useState<ErrorPanel | null>(null);
  const [guardando, setGuardando] = useState(false);
  const [sucio, setSucio] = useState(false);
  useAdvertirCambios(sucio && !guardando);

  const [convocatorias, setConvocatorias] = useState<ConvocatoriaResumen[]>([]);
  const [convocatoria, setConvocatoria] = useState('');

  // Campos de pago cuando en Nueva matrícula se elige «Abono parcial» o «Pagada».
  const [metodosPago, setMetodosPago] = useState<{ codigo: string; nombre: string }[]>([
    { codigo: 'yape', nombre: 'Yape' },
    { codigo: 'plin', nombre: 'Plin' },
    { codigo: 'efectivo', nombre: 'Efectivo' },
    { codigo: 'transferencia', nombre: 'Transferencia bancaria' },
    { codigo: 'deposito', nombre: 'Depósito bancario' },
  ]);
  const [metodoPago, setMetodoPago] = useState('yape');
  const [montoPago, setMontoPago] = useState('');
  const [fechaPago, setFechaPago] = useState(() => fechaInicial || hoyLima());
  const [referenciaPago, setReferenciaPago] = useState('');
  const [captura, setCaptura] = useState<File | null>(null);
  const [errorCaptura, setErrorCaptura] = useState<string | null>(null);
  const claveIdempotencia = useRef(crypto.randomUUID());

  useEffect(() => {
    if (matricula || !puedeConPago) return;
    let vigente = true;
    void ejecutar<{ data: { codigo: string; nombre: string }[] }>('academico/pagos/metodos').then((res) => {
      if (vigente && res.ok && res.datos.data.length > 0) {
        setMetodosPago(res.datos.data);
      }
    });
    return () => {
      vigente = false;
    };
  }, [matricula, puedeConPago, ejecutar]);

  const cursoId = curso?.id;
  const convocatoriasOfrecidas = cursoId === undefined ? [] : convocatorias;
  useEffect(() => {
    if (matricula || cursoId === undefined) return;
    let vigente = true;
    void Promise.all([
      ejecutar<{ data: ConvocatoriaResumen[] }>(`academico/convocatorias?curso_id=${encodeURIComponent(String(cursoId))}&vigentes=1`),
      ejecutar<{ data: CursoResumen }>(`academico/cursos/${encodeURIComponent(String(cursoId))}`),
    ]).then(([resConv, resCurso]) => {
      if (!vigente) return;
      const lista = resConv.ok
        ? resConv.datos.data.filter((c) => (c.estado === 'abierta' || c.estado === 'en_curso') && c.cupos.disponibles > 0)
        : [];
      setConvocatorias(lista);
      setConvocatoria(lista.length > 0 ? lista[0].identificador : '');
      if (resCurso.ok) {
        const p = Number.parseFloat(String(resCurso.datos.data.precio ?? '0'));
        setPrecioCurso(Number.isFinite(p) && p > 0 ? p : null);
      } else {
        setPrecioCurso(null);
      }
    });
    return () => {
      vigente = false;
    };
  }, [matricula, cursoId, ejecutar]);

  const buscarOpciones = useCallback(
    (tipo: 'estudiantes' | 'cursos') => async (texto: string): Promise<OpcionRemota[] | null> => {
      const parametros = new URLSearchParams({ contexto });
      if (texto) parametros.set('q', texto);
      const resultado = await ejecutar<{ data: OpcionSelector[] }>(`academico/matriculas/opciones/${tipo}?${parametros}`);
      return resultado.ok ? resultado.datos.data : null;
    },
    [contexto, ejecutar],
  );
  const buscarEstudiantes = useCallback((texto: string) => buscarOpciones('estudiantes')(texto), [buscarOpciones]);
  const buscarCursos = useCallback((texto: string) => buscarOpciones('cursos')(texto), [buscarOpciones]);

  const requiereMotivoAnulacion = Boolean(matricula) && estado === 'anulada' && matricula?.estado !== 'anulada';
  const registraPagoAhora = !matricula && puedeConPago && (estado === 'parcial' || estado === 'pagada');
  const capturaObligatoria = METODOS_CAPTURA_OBLIGATORIA.has(metodoPago);

  const abonoNumerico = Number.parseFloat(montoPago.replace(',', '.'));
  const saldoRestanteEstimado =
    estado === 'parcial' && precioCurso !== null && Number.isFinite(abonoNumerico) && abonoNumerico > 0
      ? Math.max(0, precioCurso - abonoNumerico)
      : null;

  const estados: Record<string, string> = ((): Record<string, string> => {
    if (!matricula) {
      return puedeConPago
        ? {
            pendiente: 'Pendiente (deuda total)',
            parcial: 'Abono parcial',
            pagada: 'Pagada (pago completo)',
          }
        : { pendiente: 'Pendiente' };
    }
    if (matricula.estado === 'pagada') {
      const opciones: Record<string, string> = { pagada: ETIQUETAS.estadoMatricula.pagada };
      if (puedeAnular) opciones.anulada = ETIQUETAS.estadoMatricula.anulada;
      return opciones;
    }
    const opciones: Record<string, string> = { pendiente: ETIQUETAS.estadoMatricula.pendiente };
    if (puedeAnular || matricula.estado === 'anulada') opciones.anulada = ETIQUETAS.estadoMatricula.anulada;
    return opciones;
  })();

  async function enviar(evento: FormEvent<HTMLFormElement>) {
    evento.preventDefault();
    setError(null);
    setErrorCaptura(null);

    if (registraPagoAhora) {
      if (estado === 'parcial') {
        if (!Number.isFinite(abonoNumerico) || abonoNumerico <= 0) {
          setError({ code: 'validacion', message: 'Indica el monto del abono inicial.', errors: { monto: ['Indica un abono mayor a 0.'] } });
          return;
        }
        if (precioCurso !== null && abonoNumerico >= precioCurso) {
          setError({
            code: 'validacion',
            message: 'Si el monto cubre el total del curso, selecciona estado «Pagada».',
            errors: { monto: [`Para un abono parcial ingresa menos de ${moneda(precioCurso.toFixed(2))}.`] },
          });
          return;
        }
      }
      if (capturaObligatoria) {
        const errArchivo = validarCaptura(captura);
        if (errArchivo) {
          setErrorCaptura('Adjunta el comprobante (obligatorio para Yape y Plin).');
          return;
        }
      } else if (captura !== null) {
        const errArchivo = validarCaptura(captura);
        if (errArchivo) {
          setErrorCaptura(errArchivo);
          return;
        }
      }

      setGuardando(true);
      const formulario = new FormData();
      if (estudiante) formulario.append('estudiante_id', String(estudiante.id));
      if (convocatoria !== '') {
        formulario.append('convocatoria', convocatoria);
      } else if (cursoId !== undefined) {
        formulario.append('curso_id', String(cursoId));
      }
      formulario.append('metodo', metodoPago);
      if (estado === 'parcial' && montoPago.trim() !== '') {
        formulario.append('monto', montoPago.trim().replace(',', '.'));
      } else if (estado === 'pagada' && precioCurso !== null) {
        formulario.append('monto', precioCurso.toFixed(2));
      }
      formulario.append('fecha_pago', fechaPago);
      if (referenciaPago.trim() !== '') formulario.append('referencia', referenciaPago.trim());
      formulario.append('_clave', claveIdempotencia.current);
      if (captura !== null) formulario.append('imagen', captura);

      const resultado = await subir<{ message: string; data: { matricula_id: number; obligacion: string } }>('academico/matriculas/con-pago', formulario);
      if (!resultado.ok) {
        setGuardando(false);
        setError(resultado.error);
        return;
      }
      mostrar('exito', resultado.datos.message);
      router.push(`${DETALLE}/${resultado.datos.data.matricula_id}`);
      router.refresh();
      return;
    }

    setGuardando(true);
    const cuerpo = {
      estudiante_id: estudiante ? String(estudiante.id) : null,
      curso_id: curso ? String(curso.id) : null,
      ...(matricula ? {} : { convocatoria: convocatoria === '' || cursoId === undefined ? null : convocatoria, crear_obligacion: true }),
      fecha_matricula: fechaMatricula || null,
      estado,
      ...(requiereMotivoAnulacion ? { motivo: motivo.trim() } : {}),
      observaciones: observaciones.trim() === '' ? null : observaciones.trim(),
    };
    const resultado = matricula
      ? await ejecutar<{ message: string; data: MatriculaDetalle }>(`academico/matriculas/${matricula.id}`, 'PUT', cuerpo)
      : await ejecutar<{ message: string; data: MatriculaDetalle }>('academico/matriculas', 'POST', cuerpo);

    if (!resultado.ok) {
      setGuardando(false);
      setError(resultado.error);
      return;
    }
    mostrar('exito', resultado.datos.message);
    router.push(`${DETALLE}/${resultado.datos.data.id}`);
    router.refresh();
  }

  const err = (campo: string) => errorDeCampo(error, campo);
  const cancelar = matricula ? `${DETALLE}/${matricula.id}` : DETALLE;

  return (
    <form
      onSubmit={enviar}
      onInput={() => setSucio(true)}
      onChange={() => setSucio(true)}
      noValidate
      className="space-y-5 rounded-xl border border-panel-border bg-panel-surface p-5 shadow-sm sm:p-6"
    >
      {error && error.code !== 'validacion' ? <Alerta tipo="error">{error.message}</Alerta> : null}
      {error?.code === 'validacion' ? <Alerta tipo="error" titulo="No se pudo guardar la matrícula">Revisa los campos marcados.</Alerta> : null}

      <div className="grid gap-5 lg:grid-cols-2">
        <SelectorRemoto
          etiqueta="Estudiante"
          valor={estudiante}
          onCambiar={setEstudiante}
          buscar={buscarEstudiantes}
          marcador="Busca por DNI o nombre"
          ayuda={matricula ? undefined : 'Solo estudiantes activos.'}
          error={err('estudiante_id')}
        />
        <SelectorRemoto
          etiqueta="Curso"
          valor={curso}
          onCambiar={setCurso}
          buscar={buscarCursos}
          marcador="Busca por código o nombre"
          ayuda={matricula ? undefined : precioCurso !== null ? `Precio del curso: ${moneda(precioCurso.toFixed(2))}` : 'Solo cursos activos con cupos disponibles.'}
          error={err('curso_id')}
        />

        {matricula ? (
          matricula.convocatoria ? (
            <Campo etiqueta="Edición" value={matricula.convocatoria.codigo} readOnly ayuda="Queda fija desde el registro; el curso tampoco cambia." />
          ) : null
        ) : convocatoriasOfrecidas.length > 0 ? (
          <CampoSeleccion
            etiqueta="Edición"
            value={convocatoria}
            onChange={(e) => setConvocatoria(e.target.value)}
            opciones={{
              ...Object.fromEntries(convocatoriasOfrecidas.map((c) => [c.identificador, `${c.codigo} · ${c.cupos.disponibles} de ${c.cupos.maximos} cupos`])),
              '': 'Sin edición (cupo del curso)',
            }}
            error={err('convocatoria')}
            ayuda="Se preselecciona la edición abierta más próxima."
          />
        ) : null}

        <Campo
          etiqueta={registraPagoAhora ? 'Fecha del pago' : 'Fecha de matrícula'}
          type="date"
          value={registraPagoAhora ? fechaPago : fechaMatricula}
          onChange={(e) => (registraPagoAhora ? setFechaPago(e.target.value) : setFechaMatricula(e.target.value))}
          error={err(registraPagoAhora ? 'fecha_pago' : 'fecha_matricula')}
          required
        />

        <CampoSeleccion
          etiqueta="Estado"
          value={estado}
          onChange={(e) => {
            setEstado(e.target.value);
            setErrorCaptura(null);
          }}
          opciones={estados}
          error={err('estado')}
          ayuda={
            matricula
              ? 'El estado «Pagada» se actualiza automáticamente desde Pagos.'
              : estado === 'pendiente'
                ? 'Se registra la matrícula y su deuda pasa a Pagos y deudores.'
                : estado === 'parcial'
                  ? 'Se registra el abono inicial y el saldo restante queda en Deudores.'
                  : 'Se registra el pago total y pasa a Pagos completos.'
          }
        />

        {matricula ? (
          <Campo
            etiqueta="Monto pagado"
            value={moneda(matricula.monto_pagado)}
            readOnly
            ayuda="Se actualiza automáticamente desde Pagos."
          />
        ) : null}

        {registraPagoAhora ? (
          <>
            <CampoSeleccion
              etiqueta="Método de pago"
              value={metodoPago}
              onChange={(e) => {
                setMetodoPago(e.target.value);
                setErrorCaptura(null);
              }}
              opciones={Object.fromEntries(metodosPago.map((m) => [m.codigo, m.nombre]))}
              error={err('metodo')}
            />
            {estado === 'parcial' ? (
              <Campo
                etiqueta="Monto del abono (S/)"
                inputMode="decimal"
                value={montoPago}
                onChange={(e) => setMontoPago(e.target.value)}
                error={err('monto')}
                placeholder={precioCurso !== null ? `Menor a ${precioCurso.toFixed(2)}` : 'Ej. 100.00'}
                ayuda={
                  saldoRestanteEstimado !== null
                    ? `Quedará pendiente por pagar: ${moneda(saldoRestanteEstimado.toFixed(2))}`
                    : 'Indica cuánto está abonando ahora.'
                }
                required
              />
            ) : (
              <Campo
                etiqueta="Monto total a pagar (S/)"
                value={precioCurso !== null ? precioCurso.toFixed(2) : montoPago}
                onChange={(e) => setMontoPago(e.target.value)}
                readOnly={precioCurso !== null}
                error={err('monto')}
                ayuda="Cubre el 100 % del curso."
              />
            )}
            <Campo
              etiqueta="Código de operación / referencia (opcional)"
              maxLength={80}
              value={referenciaPago}
              onChange={(e) => setReferenciaPago(e.target.value)}
              error={err('referencia')}
            />
            <div className="lg:col-span-2">
              <CampoArchivo
                etiqueta={capturaObligatoria ? 'Comprobante de pago (obligatorio)' : 'Comprobante de pago (opcional)'}
                requerido={capturaObligatoria}
                accept={ACEPTA_CAPTURA}
                onArchivo={setCaptura}
                error={errorCaptura ?? err('imagen')}
                ayuda={
                  capturaObligatoria
                    ? 'Obligatorio para Yape y Plin. JPG, PNG, WebP o PDF de hasta 3 MB.'
                    : 'Opcional para efectivo, transferencia o depósito. JPG, PNG, WebP o PDF de hasta 3 MB.'
                }
              />
            </div>
          </>
        ) : null}

        {requiereMotivoAnulacion ? (
          <CampoArea
            etiqueta="Motivo de anulación"
            rows={2}
            minLength={3}
            maxLength={200}
            value={motivo}
            onChange={(e) => setMotivo(e.target.value)}
            error={err('motivo')}
            required
            placeholder="Indica brevemente el motivo (mínimo 3 caracteres)"
            className="lg:col-span-2"
          />
        ) : null}

        {!registraPagoAhora ? (
          <CampoArea
            etiqueta="Observaciones (opcional)"
            rows={3}
            maxLength={2000}
            value={observaciones}
            onChange={(e) => setObservaciones(e.target.value)}
            error={err('observaciones')}
            className="lg:col-span-2"
          />
        ) : null}
      </div>

      <div className="pie-acciones">
        <Boton variante="secundario" onClick={() => router.push(cancelar)} disabled={guardando}>Cancelar</Boton>
        <Boton type="submit" cargando={guardando}>
          {matricula ? 'Guardar cambios' : 'Registrar matrícula'}
        </Boton>
      </div>
    </form>
  );
}
