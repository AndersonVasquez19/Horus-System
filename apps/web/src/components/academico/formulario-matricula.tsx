'use client';

import { useRouter } from 'next/navigation';
import { useCallback, useState, type FormEvent } from 'react';
import { usePuede } from '@/components/shared/sesion-panel-contexto';
import { useOperacionPanel } from '@/components/shared/use-operacion-panel';
import { Alerta } from '@/components/shared/ui/alerta';
import { useAvisos } from '@/components/shared/ui/avisos';
import { Boton } from '@/components/shared/ui/boton';
import { Campo, CampoArea, CampoSeleccion } from '@/components/shared/ui/campo';
import { SelectorRemoto, type OpcionRemota } from '@/components/shared/ui/selector-remoto';
import type { MatriculaDetalle, OpcionSelector } from '@/features/academico/tipos';
import { errorDeCampo } from '@/lib/panel/cliente';
import { ETIQUETAS } from '@/lib/panel/formato';
import type { ErrorPanel } from '@/lib/panel/tipos';

const DETALLE = '/admin/academico/matriculas';

interface FormularioMatriculaProps {
  /** Sin matrícula: registro. Con matrícula: edición. */
  matricula?: MatriculaDetalle;
  /** Preselección del registro (p. ej. desde la ficha del estudiante). */
  estudianteInicial?: OpcionRemota | null;
  /** Fecha propuesta para un registro nuevo (hoy en Lima, calculada por la página en el servidor). */
  fechaInicial?: string;
}

/**
 * Registro y edición de matrículas. Toda la regla de negocio vive en Laravel (GestionMatriculas): elegibilidad,
 * duplicado vigente, cupo, permiso para anular y protocolo de bloqueo. El formulario solo propone opciones
 * (`matriculas/opciones/*`, filtradas por contexto) y muestra los errores que Laravel devuelve.
 */
export function FormularioMatricula({ matricula, estudianteInicial = null, fechaInicial = '' }: FormularioMatriculaProps) {
  const router = useRouter();
  const { mostrar } = useAvisos();
  const { ejecutar } = useOperacionPanel();
  const puedeAnular = usePuede('matriculas.anular');
  const contexto = matricula ? 'editar' : 'crear';

  const [estudiante, setEstudiante] = useState<OpcionRemota | null>(
    matricula?.estudiante ? { id: matricula.estudiante.id, texto: `${matricula.estudiante.dni} · ${matricula.estudiante.nombre}` } : estudianteInicial,
  );
  const [curso, setCurso] = useState<OpcionRemota | null>(
    matricula?.curso ? { id: matricula.curso.id, texto: `${matricula.curso.codigo} · ${matricula.curso.nombre}` } : null,
  );
  const [fechaMatricula, setFechaMatricula] = useState(matricula?.fecha ?? fechaInicial);
  const [estado, setEstado] = useState<string>(matricula?.estado ?? 'pendiente');
  const [monto, setMonto] = useState(matricula?.monto_pagado ?? '');
  const [observaciones, setObservaciones] = useState(matricula?.observaciones ?? '');
  const [error, setError] = useState<ErrorPanel | null>(null);
  const [guardando, setGuardando] = useState(false);

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

  // «Anulada» solo se ofrece a quien puede anular, o si la matrícula ya lo está (Laravel lo vuelve a comprobar).
  const estados: Record<string, string> = puedeAnular || matricula?.estado === 'anulada'
    ? { ...ETIQUETAS.estadoMatricula }
    : { pendiente: ETIQUETAS.estadoMatricula.pendiente, pagada: ETIQUETAS.estadoMatricula.pagada };

  async function enviar(evento: FormEvent<HTMLFormElement>) {
    evento.preventDefault();
    setGuardando(true);
    setError(null);

    const cuerpo = {
      estudiante_id: estudiante ? String(estudiante.id) : null,
      curso_id: curso ? String(curso.id) : null,
      fecha_matricula: fechaMatricula || null,
      estado,
      monto_pagado: monto.trim() === '' ? null : monto.trim(),
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
    <form onSubmit={enviar} noValidate className="space-y-5 rounded-xl border border-panel-border bg-panel-surface p-5 shadow-sm sm:p-6">
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
        <Campo etiqueta="Fecha de matrícula" type="date" value={fechaMatricula} onChange={(e) => setFechaMatricula(e.target.value)} error={err('fecha_matricula')} required />
        <CampoSeleccion
          etiqueta="Estado"
          value={estado}
          onChange={(e) => setEstado(e.target.value)}
          opciones={estados}
          error={err('estado')}
          ayuda="Pendiente y pagada ocupan cupo; anulada lo libera."
        />
        <Campo
          etiqueta="Monto pagado (S/, opcional)"
          type="number"
          inputMode="decimal"
          min="0"
          max="99999999.99"
          step="0.01"
          value={monto}
          onChange={(e) => setMonto(e.target.value)}
          error={err('monto_pagado')}
        />
        <CampoArea
          etiqueta="Observaciones (opcional)"
          rows={3}
          maxLength={2000}
          value={observaciones}
          onChange={(e) => setObservaciones(e.target.value)}
          error={err('observaciones')}
          className="lg:col-span-2"
        />
      </div>

      <div className="flex flex-col-reverse gap-2 border-t border-panel-border pt-4 sm:flex-row sm:justify-end">
        <Boton variante="secundario" onClick={() => router.push(cancelar)} disabled={guardando}>Cancelar</Boton>
        <Boton type="submit" cargando={guardando}>{matricula ? 'Guardar cambios' : 'Registrar matrícula'}</Boton>
      </div>
    </form>
  );
}
