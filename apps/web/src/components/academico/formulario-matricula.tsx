'use client';

import { useRouter } from 'next/navigation';
import { useCallback, useEffect, useRef, useState, type FormEvent } from 'react';
import { usePuede } from '@/components/shared/sesion-panel-contexto';
import { useAdvertirCambios } from '@/components/shared/use-advertir-cambios';
import { useOperacionPanel } from '@/components/shared/use-operacion-panel';
import { Alerta } from '@/components/shared/ui/alerta';
import { useAvisos } from '@/components/shared/ui/avisos';
import { Boton } from '@/components/shared/ui/boton';
import { Campo, CampoArea, CampoSeleccion } from '@/components/shared/ui/campo';
import { SelectorRemoto, type OpcionRemota } from '@/components/shared/ui/selector-remoto';
import type { ConvocatoriaResumen, MatriculaDetalle, OpcionSelector } from '@/features/academico/tipos';
import { errorDeCampo } from '@/lib/panel/cliente';
import { ETIQUETAS, hoyLima, moneda } from '@/lib/panel/formato';
import type { ErrorPanel } from '@/lib/panel/tipos';

const DETALLE = '/admin/academico/matriculas';
const MAX_CAPTURA = 3 * 1024 * 1024;

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
 * Registro y edición de matrículas. `monto_pagado` y el estado `pagada` se derivan siempre del libro de pagos;
 * en una matrícula nueva con edición elegible se puede adjuntar opcionalmente un pago Yape inicial desde la caja
 * (`academico/matriculas/con-pago`).
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
  const [fechaMatricula, setFechaMatricula] = useState(matricula?.fecha ?? fechaInicial);
  const [estado, setEstado] = useState<string>(matricula?.estado ?? 'pendiente');
  const [motivo, setMotivo] = useState('');
  const [observaciones, setObservaciones] = useState(matricula?.observaciones ?? '');
  const [error, setError] = useState<ErrorPanel | null>(null);
  const [guardando, setGuardando] = useState(false);
  /** Se activa en cuanto la persona escribe o elige algo; avisa antes de cerrar la pestaña con cambios sin guardar. */
  const [sucio, setSucio] = useState(false);
  useAdvertirCambios(sucio && !guardando);

  // Convocatorias matriculables del curso elegido (solo al registrar; después la convocatoria queda fija).
  const [convocatorias, setConvocatorias] = useState<ConvocatoriaResumen[]>([]);
  const [convocatoria, setConvocatoria] = useState('');

  // Opción de matrícula con pago Yape inicial en `matriculas/nueva`.
  const [conPagoInicial, setConPagoInicial] = useState(false);
  const [montoPago, setMontoPago] = useState('');
  const [fechaPago, setFechaPago] = useState(() => fechaInicial || hoyLima());
  const [referenciaPago, setReferenciaPago] = useState('');
  const [captura, setCaptura] = useState<File | null>(null);
  const [errorCaptura, setErrorCaptura] = useState<string | null>(null);
  const claveIdempotencia = useRef(crypto.randomUUID());

  const cursoId = curso?.id;
  const convocatoriasOfrecidas = cursoId === undefined ? [] : convocatorias;
  useEffect(() => {
    if (matricula || cursoId === undefined) return;
    let vigente = true;
    void ejecutar<{ data: ConvocatoriaResumen[] }>(`academico/convocatorias?curso_id=${encodeURIComponent(String(cursoId))}&vigentes=1`).then((resultado) => {
      if (!vigente) return;
      const lista = resultado.ok ? resultado.datos.data : [];
      setConvocatorias(lista.filter((c) => (c.estado === 'abierta' || c.estado === 'en_curso') && c.cupos.disponibles > 0));
      setConvocatoria('');
      setConPagoInicial(false);
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

  const requiereMotivoAnulacion = estado === 'anulada' && matricula?.estado !== 'anulada';
  const admitePagoInicial = !matricula && puedeConPago && convocatoria !== '' && cursoId !== undefined;

  const estados: Record<string, string> = (() => {
    if (!matricula) {
      return puedeAnular
        ? { pendiente: ETIQUETAS.estadoMatricula.pendiente, anulada: ETIQUETAS.estadoMatricula.anulada }
        : { pendiente: ETIQUETAS.estadoMatricula.pendiente };
    }
    if (matricula.estado === 'pagada') {
      return {
        pagada: ETIQUETAS.estadoMatricula.pagada,
        ...(puedeAnular ? { anulada: ETIQUETAS.estadoMatricula.anulada } : {}),
      };
    }
    return {
      pendiente: ETIQUETAS.estadoMatricula.pendiente,
      ...(puedeAnular || matricula.estado === 'anulada' ? { anulada: ETIQUETAS.estadoMatricula.anulada } : {}),
    };
  })();

  async function enviar(evento: FormEvent<HTMLFormElement>) {
    evento.preventDefault();
    setError(null);
    setErrorCaptura(null);

    if (admitePagoInicial && conPagoInicial) {
      if (captura === null) {
        setErrorCaptura('Adjunta la captura del comprobante de Yape.');
        return;
      }
      if (!['image/jpeg', 'image/png', 'image/webp'].includes(captura.type) || captura.size > MAX_CAPTURA) {
        setErrorCaptura('Sube una imagen JPG, PNG o WebP de hasta 3 MB.');
        return;
      }
      setGuardando(true);
      const formulario = new FormData();
      if (estudiante) formulario.append('estudiante_id', String(estudiante.id));
      formulario.append('convocatoria', convocatoria);
      if (montoPago.trim() !== '') formulario.append('monto', montoPago.trim());
      formulario.append('fecha_pago', fechaPago);
      if (referenciaPago.trim() !== '') formulario.append('referencia', referenciaPago.trim());
      formulario.append('_clave', claveIdempotencia.current);
      formulario.append('imagen', captura);

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
      ...(matricula ? {} : { convocatoria: convocatoria === '' || cursoId === undefined ? null : convocatoria }),
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
          ayuda={matricula ? undefined : 'Solo cursos activos con cupos disponibles.'}
          error={err('curso_id')}
        />
        {matricula ? (
          matricula.convocatoria ? (
            <Campo etiqueta="Edición" value={matricula.convocatoria.codigo} readOnly ayuda="Queda fija desde el registro; el curso tampoco cambia." />
          ) : null
        ) : convocatoriasOfrecidas.length > 0 ? (
          <CampoSeleccion
            etiqueta="Edición (opcional)"
            value={convocatoria}
            onChange={(e) => {
              setConvocatoria(e.target.value);
              if (e.target.value === '') setConPagoInicial(false);
            }}
            opciones={{
              '': 'Sin edición (cupo del curso)',
              ...Object.fromEntries(convocatoriasOfrecidas.map((c) => [c.identificador, `${c.codigo} · ${c.cupos.disponibles} de ${c.cupos.maximos} cupos`])),
            }}
            error={err('convocatoria')}
            ayuda="Si eliges una, el cupo se descuenta de la edición y no del curso."
          />
        ) : null}

        {!(admitePagoInicial && conPagoInicial) ? (
          <>
            <Campo etiqueta="Fecha de matrícula" type="date" value={fechaMatricula} onChange={(e) => setFechaMatricula(e.target.value)} error={err('fecha_matricula')} required />
            <CampoSeleccion
              etiqueta="Estado"
              value={estado}
              onChange={(e) => setEstado(e.target.value)}
              opciones={estados}
              error={err('estado')}
              ayuda="El estado «Pagada» se asigna automáticamente desde Finanzas al confirmar el pago."
            />
          </>
        ) : null}

        {matricula ? (
          <Campo
            etiqueta="Monto pagado (libro de pagos)"
            value={moneda(matricula.monto_pagado)}
            readOnly
            ayuda="Se actualiza automáticamente cuando se confirman o reversan pagos en Finanzas."
          />
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

        {admitePagoInicial ? (
          <div className="space-y-4 rounded-xl border border-academico-200 bg-academico-50/40 p-4 lg:col-span-2">
            <label className="flex cursor-pointer items-center gap-2 text-sm font-semibold text-panel-text">
              <input
                type="checkbox"
                checked={conPagoInicial}
                onChange={(e) => setConPagoInicial(e.target.checked)}
                className="h-4 w-4 rounded border-panel-border accent-academico-700"
              />
              Registrar también el pago completo con Yape y comprobante en un solo paso
            </label>
            {conPagoInicial ? (
              <div className="grid gap-4 sm:grid-cols-2">
                <Campo
                  etiqueta="Importe (S/)"
                  inputMode="decimal"
                  value={montoPago}
                  onChange={(e) => setMontoPago(e.target.value)}
                  error={err('monto')}
                  ayuda="Déjalo vacío para cobrar el precio del curso."
                />
                <Campo etiqueta="Fecha del pago" type="date" value={fechaPago} onChange={(e) => setFechaPago(e.target.value)} error={err('fecha_pago')} required />
                <Campo etiqueta="Código de operación Yape (opcional)" maxLength={80} value={referenciaPago} onChange={(e) => setReferenciaPago(e.target.value)} error={err('referencia')} />
                <Campo
                  etiqueta="Captura del comprobante Yape"
                  type="file"
                  accept="image/jpeg,image/png,image/webp"
                  onChange={(e) => setCaptura(e.target.files?.[0] ?? null)}
                  error={errorCaptura ?? err('imagen')}
                  ayuda="JPG, PNG o WebP de hasta 3 MB."
                  required
                />
              </div>
            ) : null}
          </div>
        ) : null}

        {!(admitePagoInicial && conPagoInicial) ? (
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
          {matricula ? 'Guardar cambios' : conPagoInicial && admitePagoInicial ? 'Matricular y registrar pago' : 'Registrar matrícula'}
        </Boton>
      </div>
    </form>
  );
}
