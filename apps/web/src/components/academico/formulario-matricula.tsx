'use client';

import { CheckCircle2, CircleDollarSign, Clock, Users } from 'lucide-react';
import { useRouter } from 'next/navigation';
import { useCallback, useEffect, useId, useRef, useState, type FormEvent } from 'react';
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

const MODALIDADES_COBRO = [
  {
    valor: 'pendiente',
    titulo: 'Dejar pendiente',
    detalle: 'Genera la deuda total en Pagos y deudores.',
    icono: Clock,
  },
  {
    valor: 'parcial',
    titulo: 'Abono parcial',
    detalle: 'Registra un adelanto y deja el saldo pendiente.',
    icono: CircleDollarSign,
  },
  {
    valor: 'pagada',
    titulo: 'Pago completo',
    detalle: 'Registra el 100 % del curso ahora mismo.',
    icono: CheckCircle2,
  },
] as const;

export interface FormularioMatriculaProps {
  /** Sin matrícula: registro. Con matrícula: edición. */
  matricula?: MatriculaDetalle;
  /** Preselección del registro (p. ej. desde la ficha del estudiante). */
  estudianteInicial?: OpcionRemota | null;
  /** Preselección del curso (p. ej. desde su ficha), solo si es elegible. */
  cursoInicial?: OpcionRemota | null;
  /** Fecha propuesta para un registro nuevo (hoy en Lima). */
  fechaInicial?: string;
  /** Cuando vive dentro de `<Dialogo>`, elimina el envoltorio de tarjeta y delega el cierre y la advertencia de cambios. */
  enModal?: boolean;
  onCancelar?: () => void;
  onOcupado?: (ocupado: boolean) => void;
  onGuardado?: (resultado: { id: number; mensaje: string }) => void;
}

/**
 * Formulario de creación y edición de matrículas.
 * Organizado en bloques semánticos (Datos académicos y Cobro inicial) para funcionar en modal centrado o en página.
 */
export function FormularioMatricula({
  matricula,
  estudianteInicial = null,
  cursoInicial = null,
  fechaInicial = '',
  enModal = false,
  onCancelar,
  onOcupado,
  onGuardado,
}: FormularioMatriculaProps) {
  const router = useRouter();
  const { mostrar } = useAvisos();
  const { ejecutar, subir } = useOperacionPanel();
  const puedeAnular = usePuede('matriculas.anular');
  const puedeConPago = [usePuede('matriculas.crear'), usePuede('pagos.planificar'), usePuede('pagos.registrar'), usePuede('pagos.confirmar')].every(Boolean);
  const contexto = matricula ? 'editar' : 'crear';
  const idGrupoCobro = useId();
  const hoy = fechaInicial || hoyLima();

  const [estudiante, setEstudiante] = useState<OpcionRemota | null>(
    matricula?.estudiante ? { id: matricula.estudiante.id, texto: `${matricula.estudiante.dni} · ${matricula.estudiante.nombre}` } : estudianteInicial,
  );
  const [curso, setCurso] = useState<OpcionRemota | null>(
    matricula?.curso ? { id: matricula.curso.id, texto: `${matricula.curso.codigo} · ${matricula.curso.nombre}` } : cursoInicial,
  );
  const [cursoInfo, setCursoInfo] = useState<{ precio: number | null; cuposDisponibles: number | null; cuposMaximos: number | null } | null>(null);
  const [cursoCargadoId, setCursoCargadoId] = useState<number | null>(null);
  const [fechaMatricula, setFechaMatricula] = useState(matricula?.fecha ?? hoy);
  const [estado, setEstado] = useState<string>(matricula?.estado ?? 'pendiente');
  const [motivo, setMotivo] = useState('');
  const [observaciones, setObservaciones] = useState(matricula?.observaciones ?? '');
  const [error, setError] = useState<ErrorPanel | null>(null);
  const [guardando, setGuardando] = useState(false);
  const [sucio, setSucio] = useState(false);
  useAdvertirCambios(sucio && !guardando && !enModal);

  const [convocatorias, setConvocatorias] = useState<ConvocatoriaResumen[]>([]);
  const [convocatoria, setConvocatoria] = useState('');

  // Campos de pago cuando en Nueva matrícula se elige «Abono parcial» o «Pago completo».
  const [metodosPago, setMetodosPago] = useState<{ codigo: string; nombre: string }[]>([
    { codigo: 'yape', nombre: 'Yape' },
    { codigo: 'plin', nombre: 'Plin' },
    { codigo: 'efectivo', nombre: 'Efectivo' },
    { codigo: 'transferencia', nombre: 'Transferencia bancaria' },
    { codigo: 'deposito', nombre: 'Depósito bancario' },
  ]);
  const [metodoPago, setMetodoPago] = useState('yape');
  const [montoPago, setMontoPago] = useState('');
  const [fechaPago, setFechaPago] = useState(hoy);
  const [referenciaPago, setReferenciaPago] = useState('');
  const [alertaReferencia, setAlertaReferencia] = useState<{ referencia: string; mensaje: string } | null>(null);
  const [captura, setCaptura] = useState<File | null>(null);
  const [errorCaptura, setErrorCaptura] = useState<string | null>(null);
  const claveIdempotencia = useRef(crypto.randomUUID());

  const cambiarGuardando = useCallback(
    (valor: boolean) => {
      setGuardando(valor);
      onOcupado?.(valor);
    },
    [onOcupado],
  );

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
  const cargandoCurso = !matricula && cursoId !== undefined && cursoCargadoId !== cursoId;
  const convocatoriasOfrecidas = cursoId === undefined || cargandoCurso ? [] : convocatorias;
  const precioCurso = cursoId === undefined || cargandoCurso ? null : (cursoInfo?.precio ?? null);

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
        setCursoInfo({
          precio: Number.isFinite(p) && p > 0 ? p : null,
          cuposDisponibles: resCurso.datos.data.cupos_disponibles,
          cuposMaximos: resCurso.datos.data.cupos_maximos,
        });
      } else {
        setCursoInfo(null);
      }
      setCursoCargadoId(cursoId);
    });
    return () => {
      vigente = false;
    };
  }, [matricula, cursoId, ejecutar]);

  const registraPagoAhora = !matricula && puedeConPago && (estado === 'parcial' || estado === 'pagada');
  const referenciaLimpia = referenciaPago.trim();
  const alertaReferenciaVisible =
    registraPagoAhora && referenciaLimpia.length >= 3 && alertaReferencia?.referencia === referenciaLimpia
      ? alertaReferencia.mensaje
      : null;

  // Comprueba referencia duplicada al escribir un código de operación (igual que en Ediciones → Matricular con pago).
  useEffect(() => {
    if (!registraPagoAhora || referenciaLimpia.length < 3) return undefined;
    let vigente = true;
    const temporizador = window.setTimeout(async () => {
      const r = await ejecutar<{ data: { duplicada: boolean; coincidencias: { matricula_id: number; fecha_pago: string }[] } }>(
        `academico/pagos/verificar-referencia?referencia=${encodeURIComponent(referenciaLimpia)}`,
        'GET',
      );
      if (!vigente) return;
      if (r.ok && r.datos.data.duplicada) {
        const primera = r.datos.data.coincidencias[0];
        setAlertaReferencia({
          referencia: referenciaLimpia,
          mensaje: primera
            ? `Advertencia: este código de operación ya se registró en la matrícula #${primera.matricula_id} (${primera.fecha_pago}).`
            : 'Advertencia: este código de operación ya fue registrado anteriormente.',
        });
      } else {
        setAlertaReferencia(null);
      }
    }, 350);
    return () => {
      vigente = false;
      window.clearTimeout(temporizador);
    };
  }, [ejecutar, referenciaLimpia, registraPagoAhora]);

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
  const capturaObligatoria = METODOS_CAPTURA_OBLIGATORIA.has(metodoPago);

  const abonoNumerico = Number.parseFloat(montoPago.replace(',', '.'));
  const saldoRestanteEstimado =
    estado === 'parcial' && precioCurso !== null && Number.isFinite(abonoNumerico) && abonoNumerico > 0
      ? Math.max(0, precioCurso - abonoNumerico)
      : null;

  const estadosEdicion: Record<string, string> = ((): Record<string, string> => {
    if (!matricula) return { pendiente: 'Pendiente' };
    if (matricula.estado === 'pagada') {
      const opciones: Record<string, string> = { pagada: ETIQUETAS.estadoMatricula.pagada };
      if (puedeAnular) opciones.anulada = ETIQUETAS.estadoMatricula.anulada;
      return opciones;
    }
    const opciones: Record<string, string> = { pendiente: ETIQUETAS.estadoMatricula.pendiente };
    if (puedeAnular || matricula.estado === 'anulada') opciones.anulada = ETIQUETAS.estadoMatricula.anulada;
    return opciones;
  })();

  function finalizarExito(id: number, mensaje: string) {
    if (onGuardado) {
      onGuardado({ id, mensaje });
      return;
    }
    mostrar('exito', mensaje);
    router.push(`${DETALLE}/${id}`);
    router.refresh();
  }

  async function enviar(evento: FormEvent<HTMLFormElement>) {
    evento.preventDefault();
    setError(null);
    setErrorCaptura(null);

    if (registraPagoAhora) {
      if (!estudiante || cursoId === undefined) {
        setError({
          code: 'validacion',
          message: 'Completa los campos obligatorios.',
          errors: {
            ...(!estudiante ? { estudiante_id: ['Selecciona un estudiante.'] } : {}),
            ...(cursoId === undefined ? { curso_id: ['Selecciona un curso.'] } : {}),
          },
        });
        return;
      }
      if (estado === 'parcial') {
        if (!Number.isFinite(abonoNumerico) || abonoNumerico <= 0) {
          setError({ code: 'validacion', message: 'Indica el monto del abono inicial.', errors: { monto: ['Indica un abono mayor a 0.'] } });
          return;
        }
        if (precioCurso !== null && abonoNumerico >= precioCurso) {
          setError({
            code: 'validacion',
            message: 'Si el monto cubre el total del curso, selecciona «Pago completo».',
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

      cambiarGuardando(true);
      const formulario = new FormData();
      formulario.append('estudiante_id', String(estudiante.id));
      if (convocatoria !== '') {
        formulario.append('convocatoria', convocatoria);
      } else {
        formulario.append('curso_id', String(cursoId));
      }
      formulario.append('metodo', metodoPago);
      if (estado === 'parcial' && montoPago.trim() !== '') {
        formulario.append('monto', montoPago.trim().replace(',', '.'));
      } else if (estado === 'pagada' && precioCurso !== null) {
        formulario.append('monto', precioCurso.toFixed(2));
      } else if (estado === 'pagada' && montoPago.trim() !== '') {
        formulario.append('monto', montoPago.trim().replace(',', '.'));
      }
      formulario.append('fecha_pago', fechaPago);
      if (referenciaPago.trim() !== '') formulario.append('referencia', referenciaPago.trim());
      formulario.append('_clave', claveIdempotencia.current);
      if (captura !== null) formulario.append('imagen', captura);

      const resultado = await subir<{ message: string; data: { matricula_id: number; obligacion: string } }>('academico/matriculas/con-pago', formulario);
      cambiarGuardando(false);
      if (!resultado.ok) {
        setError(resultado.error);
        return;
      }
      finalizarExito(resultado.datos.data.matricula_id, resultado.datos.message);
      return;
    }

    cambiarGuardando(true);
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

    cambiarGuardando(false);
    if (!resultado.ok) {
      setError(resultado.error);
      return;
    }
    finalizarExito(resultado.datos.data.id, resultado.datos.message);
  }

  const err = (campo: string) => errorDeCampo(error, campo);
  const cancelarHref = matricula ? `${DETALLE}/${matricula.id}` : DETALLE;
  const edicionSeleccionada = convocatoriasOfrecidas.find((c) => c.identificador === convocatoria) ?? null;

  return (
    <form
      onSubmit={enviar}
      onInput={() => setSucio(true)}
      onChange={() => setSucio(true)}
      noValidate
      className={enModal ? 'space-y-5' : 'space-y-5 rounded-xl border border-panel-border bg-panel-surface p-5 shadow-sm sm:p-6'}
    >
      {error && error.code !== 'validacion' ? <Alerta tipo="error">{error.message}</Alerta> : null}
      {error?.code === 'validacion' ? <Alerta tipo="error" titulo="No se pudo guardar la matrícula">Revisa los campos marcados.</Alerta> : null}

      {/* Bloque 1: Datos académicos */}
      <div className="space-y-4">
        <div className="grid gap-4 sm:grid-cols-2">
          <SelectorRemoto
            etiqueta="Estudiante"
            requerido
            valor={estudiante}
            onCambiar={setEstudiante}
            buscar={buscarEstudiantes}
            marcador="Busca por DNI o nombre"
            ayuda={matricula ? undefined : 'Solo estudiantes activos.'}
            error={err('estudiante_id')}
          />
          <SelectorRemoto
            etiqueta="Curso"
            requerido
            valor={curso}
            onCambiar={(opcion) => {
              setCurso(opcion);
              if (opcion === null) {
                setConvocatorias([]);
                setConvocatoria('');
                setCursoInfo(null);
                setCursoCargadoId(null);
              }
            }}
            buscar={buscarCursos}
            marcador="Busca por código o nombre"
            ayuda={matricula ? undefined : 'Solo cursos activos con cupos disponibles.'}
            error={err('curso_id')}
          />
        </div>

        {/* Resumen vivo del curso elegido en creación */}
        {!matricula && cursoId !== undefined ? (
          cargandoCurso ? (
            <div className="h-12 animate-pulse rounded-lg border border-panel-border bg-panel-bg" aria-busy="true" aria-label="Cargando datos del curso" />
          ) : cursoInfo ? (
            <div className="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-panel-border bg-panel-bg/70 px-3.5 py-2.5 text-sm">
              <div className="flex flex-wrap items-center gap-x-5 gap-y-1">
                <span className="text-panel-muted">
                  Precio:{' '}
                  <strong className="font-semibold tabular-nums text-panel-text">
                    {cursoInfo.precio !== null ? moneda(cursoInfo.precio.toFixed(2)) : 'Por definir'}
                  </strong>
                </span>
                <span className="inline-flex items-center gap-1.5 text-panel-muted">
                  <Users className="h-3.5 w-3.5 text-academico-700" aria-hidden="true" />
                  Cupos:{' '}
                  <strong className="font-semibold tabular-nums text-panel-text">
                    {edicionSeleccionada
                      ? `${edicionSeleccionada.cupos.disponibles} de ${edicionSeleccionada.cupos.maximos} (edición)`
                      : cursoInfo.cuposDisponibles !== null && cursoInfo.cuposMaximos !== null
                        ? `${cursoInfo.cuposDisponibles} de ${cursoInfo.cuposMaximos} (curso)`
                        : '—'}
                  </strong>
                </span>
              </div>
              {convocatoriasOfrecidas.length > 0 ? (
                <span className="text-xs font-medium text-academico-800">
                  {convocatoriasOfrecidas.length === 1 ? '1 edición abierta' : `${convocatoriasOfrecidas.length} ediciones abiertas`}
                </span>
              ) : null}
            </div>
          ) : null
        ) : null}

        <div className="grid gap-4 sm:grid-cols-2">
          {matricula ? (
            matricula.convocatoria ? (
              <Campo etiqueta="Edición" value={matricula.convocatoria.codigo} readOnly ayuda="Fija desde el registro." />
            ) : null
          ) : convocatoriasOfrecidas.length > 0 ? (
            <CampoSeleccion
              etiqueta="Edición"
              value={convocatoria}
              onChange={(e) => setConvocatoria(e.target.value)}
              opciones={{
                ...Object.fromEntries(
                  convocatoriasOfrecidas.map((c) => [c.identificador, `${c.codigo} · ${c.cupos.disponibles}/${c.cupos.maximos} cupos`]),
                ),
                '': 'Sin edición (cupo del curso)',
              }}
              error={err('convocatoria')}
              ayuda="Se preselecciona la edición abierta más próxima."
            />
          ) : null}

          {!registraPagoAhora ? (
            <Campo
              etiqueta="Fecha de matrícula"
              type="date"
              value={fechaMatricula}
              onChange={(e) => setFechaMatricula(e.target.value)}
              error={err('fecha_matricula')}
              required
            />
          ) : null}

          {matricula ? (
            <>
              <CampoSeleccion
                etiqueta="Estado"
                value={estado}
                onChange={(e) => setEstado(e.target.value)}
                opciones={estadosEdicion}
                error={err('estado')}
                ayuda="El estado «Pagada» se actualiza automáticamente desde Pagos."
              />
              <Campo etiqueta="Monto pagado (libro)" value={moneda(matricula.monto_pagado)} readOnly ayuda="Se calcula desde los pagos confirmados." />
            </>
          ) : null}
        </div>
      </div>

      {/* Bloque 2: Modalidad de cobro inicial (creación con permisos de pago) */}
      {!matricula && puedeConPago ? (
        <div className="space-y-4 border-t border-panel-border pt-5">
          <div>
            <p id={idGrupoCobro} className="text-sm font-semibold text-panel-text">
              Situación de cobro inicial
            </p>
            <p className="mt-0.5 text-xs text-panel-muted">Elige si la matrícula queda con deuda pendiente o si el estudiante paga ahora.</p>
          </div>

          <div role="radiogroup" aria-labelledby={idGrupoCobro} className="grid gap-2.5 sm:grid-cols-3">
            {MODALIDADES_COBRO.map((opcion) => {
              const Icono = opcion.icono;
              const activa = estado === opcion.valor;
              return (
                <label
                  key={opcion.valor}
                  className={`flex cursor-pointer flex-col gap-1 rounded-lg border p-3 transition focus-within:ring-2 focus-within:ring-academico-700/40 ${
                    activa
                      ? 'border-academico-700 bg-academico-50/70 ring-1 ring-academico-700'
                      : 'border-panel-border bg-panel-surface hover:border-academico-300 hover:bg-panel-bg/40'
                  }`}
                >
                  <input
                    type="radio"
                    name="modalidad_cobro"
                    value={opcion.valor}
                    checked={activa}
                    onChange={() => {
                      setEstado(opcion.valor);
                      setErrorCaptura(null);
                    }}
                    className="sr-only"
                  />
                  <span className="flex items-center gap-2 text-sm font-semibold text-panel-text">
                    <Icono className={`h-4 w-4 shrink-0 ${activa ? 'text-academico-700' : 'text-panel-muted'}`} aria-hidden="true" />
                    {opcion.titulo}
                  </span>
                  <span className="text-xs leading-snug text-panel-muted">{opcion.detalle}</span>
                </label>
              );
            })}
          </div>

          {registraPagoAhora ? (
            <div className="space-y-4 rounded-lg border border-panel-border bg-panel-bg/50 p-4">
              <div className="grid gap-4 sm:grid-cols-2">
                <CampoSeleccion
                  etiqueta="Método de pago"
                  value={metodoPago}
                  onChange={(e) => {
                    setMetodoPago(e.target.value);
                    setErrorCaptura(null);
                  }}
                  opciones={Object.fromEntries(metodosPago.map((m) => [m.codigo, m.nombre]))}
                  error={err('metodo')}
                  required
                />
                <Campo
                  etiqueta="Fecha del pago"
                  type="date"
                  value={fechaPago}
                  onChange={(e) => setFechaPago(e.target.value)}
                  error={err('fecha_pago')}
                  required
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
                        ? `Saldo pendiente: ${moneda(saldoRestanteEstimado.toFixed(2))}`
                        : 'Indica cuánto está abonando ahora.'
                    }
                    required
                  />
                ) : (
                  <Campo
                    etiqueta="Monto total a cobrar (S/)"
                    value={precioCurso !== null ? precioCurso.toFixed(2) : montoPago}
                    onChange={(e) => setMontoPago(e.target.value)}
                    readOnly={precioCurso !== null}
                    error={err('monto')}
                    ayuda={precioCurso !== null ? 'Cubre el 100 % del precio del curso.' : 'Selecciona un curso o indica el importe.'}
                  />
                )}
                <Campo
                  etiqueta="Código de operación / referencia (opcional)"
                  maxLength={80}
                  value={referenciaPago}
                  onChange={(e) => setReferenciaPago(e.target.value)}
                  error={err('referencia')}
                  placeholder="Ej. 0123456"
                />
              </div>

              {alertaReferenciaVisible ? <Alerta tipo="aviso">{alertaReferenciaVisible}</Alerta> : null}

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

              {observaciones.trim() !== '' ? (
                <p className="text-xs text-panel-muted">
                  Las observaciones se agregan desde Editar una vez creada la matrícula con pago.
                </p>
              ) : null}
            </div>
          ) : null}
        </div>
      ) : null}

      {/* Motivo de anulación en edición */}
      {requiereMotivoAnulacion ? (
        <div className="border-t border-panel-border pt-4">
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
          />
        </div>
      ) : null}

      {/* Observaciones (modo estándar y edición) */}
      {!registraPagoAhora ? (
        <div className={matricula ? 'border-t border-panel-border pt-4' : ''}>
          <CampoArea
            etiqueta="Observaciones (opcional)"
            rows={2}
            maxLength={2000}
            value={observaciones}
            onChange={(e) => setObservaciones(e.target.value)}
            error={err('observaciones')}
            placeholder="Notas internas de seguimiento, horarios o acuerdo de pago"
          />
        </div>
      ) : null}

      <div className="pie-acciones">
        <Boton
          variante="secundario"
          onClick={() => (onCancelar ? onCancelar() : router.push(cancelarHref))}
          disabled={guardando}
        >
          Cancelar
        </Boton>
        <Boton type="submit" cargando={guardando}>
          {matricula ? 'Guardar cambios' : registraPagoAhora ? 'Registrar matrícula y pago' : 'Registrar matrícula'}
        </Boton>
      </div>
    </form>
  );
}
