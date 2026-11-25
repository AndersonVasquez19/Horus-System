'use client';

import { useEffect, useState } from 'react';
import { useRouter } from 'next/navigation';
import { useOperacionPanel } from '@/components/shared/use-operacion-panel';
import { Alerta } from '@/components/shared/ui/alerta';
import { useAvisos } from '@/components/shared/ui/avisos';
import { Boton } from '@/components/shared/ui/boton';
import { Dialogo } from '@/components/shared/ui/dialogo';
import type { OpcionRemota } from '@/components/shared/ui/selector-remoto';
import type { CursoDetalle, EstudianteDetalle, MatriculaDetalle } from '@/features/academico/tipos';
import { FormularioMatricula } from './formulario-matricula';

interface ModalNuevaMatriculaProps {
  abierto: boolean;
  onCerrar: () => void;
  /** Preselección cuando se llega desde Estudiantes (`?estudiante_id=`). */
  estudianteInicialId?: string | null;
  /** Preselección cuando se llega desde Cursos (`?curso_id=`). */
  cursoInicialId?: string | null;
  /** Fecha actual en Lima calculada por el servidor. */
  fechaInicial?: string;
}

/** Modal centrado amplio para registrar una nueva matrícula sin abandonar el listado. */
export function ModalNuevaMatricula({
  abierto,
  onCerrar,
  estudianteInicialId = null,
  cursoInicialId = null,
  fechaInicial,
}: ModalNuevaMatriculaProps) {
  const [ocupado, setOcupado] = useState(false);

  return (
    <Dialogo
      abierto={abierto}
      lado="centro"
      ancho="xl"
      titulo="Nueva matrícula"
      descripcion="El cupo se reserva al guardar tras comprobar disponibilidad y duplicados."
      onCerrar={onCerrar}
      bloqueado={ocupado}
    >
      {abierto ? (
        <ContenidoNuevaMatricula
          estudianteInicialId={estudianteInicialId}
          cursoInicialId={cursoInicialId}
          fechaInicial={fechaInicial}
          onOcupado={setOcupado}
          onCerrar={onCerrar}
        />
      ) : null}
    </Dialogo>
  );
}

function ContenidoNuevaMatricula({
  estudianteInicialId,
  cursoInicialId,
  fechaInicial,
  onOcupado,
  onCerrar,
}: {
  estudianteInicialId: string | null;
  cursoInicialId: string | null;
  fechaInicial?: string;
  onOcupado: (v: boolean) => void;
  onCerrar: () => void;
}) {
  const router = useRouter();
  const { mostrar } = useAvisos();
  const { ejecutar } = useOperacionPanel();
  const requierePrecarga = Boolean(
    (estudianteInicialId && /^\d{1,10}$/.test(estudianteInicialId)) || (cursoInicialId && /^\d{1,10}$/.test(cursoInicialId)),
  );
  const [cargando, setCargando] = useState(requierePrecarga);
  const [estudianteInicial, setEstudianteInicial] = useState<OpcionRemota | null>(null);
  const [cursoInicial, setCursoInicial] = useState<OpcionRemota | null>(null);

  useEffect(() => {
    if (!requierePrecarga) return;
    let vigente = true;
    const promesas: Promise<void>[] = [];

    if (estudianteInicialId && /^\d{1,10}$/.test(estudianteInicialId)) {
      promesas.push(
        ejecutar<{ data: EstudianteDetalle }>(`academico/estudiantes/${estudianteInicialId}`).then((res) => {
          if (!vigente || !res.ok) return;
          const e = res.datos.data;
          if (e.estado === 'activo' && !e.eliminado) {
            setEstudianteInicial({ id: e.id, texto: `${e.dni} · ${e.nombres} ${e.apellidos}` });
          }
        }),
      );
    }

    if (cursoInicialId && /^\d{1,10}$/.test(cursoInicialId)) {
      promesas.push(
        ejecutar<{ data: CursoDetalle }>(`academico/cursos/${cursoInicialId}`).then((res) => {
          if (!vigente || !res.ok) return;
          const c = res.datos.data;
          if (c.estado === 'activo' && !c.eliminado && c.cupos_disponibles > 0) {
            setCursoInicial({ id: c.id, texto: `${c.codigo} · ${c.nombre} (${c.cupos_disponibles} cupos)` });
          }
        }),
      );
    }

    void Promise.all(promesas).finally(() => {
      if (vigente) setCargando(false);
    });

    return () => {
      vigente = false;
    };
  }, [requierePrecarga, estudianteInicialId, cursoInicialId, ejecutar]);

  if (cargando) {
    return (
      <div className="space-y-4 py-2" aria-busy="true" aria-label="Cargando selección inicial">
        <div className="grid gap-4 sm:grid-cols-2">
          <div className="h-16 animate-pulse rounded-lg bg-panel-bg" />
          <div className="h-16 animate-pulse rounded-lg bg-panel-bg" />
        </div>
        <div className="h-24 animate-pulse rounded-lg bg-panel-bg" />
      </div>
    );
  }

  return (
    <FormularioMatricula
      enModal
      estudianteInicial={estudianteInicial}
      cursoInicial={cursoInicial}
      fechaInicial={fechaInicial}
      onOcupado={onOcupado}
      onCancelar={onCerrar}
      onGuardado={({ mensaje }) => {
        onCerrar();
        mostrar('exito', mensaje);
        router.refresh();
      }}
    />
  );
}

interface ModalEditarMatriculaProps {
  abierto: boolean;
  onCerrar: () => void;
  /** Cuando se abre desde el listado se pasa solo el ID y el modal consulta el detalle. */
  matriculaId: number | null;
  /** Cuando se abre desde la ficha de detalle se puede pasar ya cargada para evitar una petición extra. */
  matriculaInicial?: MatriculaDetalle | null;
}

/** Modal centrado amplio para editar una matrícula desde el listado o desde su ficha. */
export function ModalEditarMatricula({ abierto, onCerrar, matriculaId, matriculaInicial = null }: ModalEditarMatriculaProps) {
  const [ocupado, setOcupado] = useState(false);
  const idTitulo = matriculaInicial?.id ?? matriculaId;

  return (
    <Dialogo
      abierto={abierto && idTitulo !== null}
      lado="centro"
      ancho="xl"
      titulo={idTitulo !== null ? `Editar matrícula #${idTitulo}` : 'Editar matrícula'}
      descripcion="Cambiar el curso traslada el cupo; pasar a «Anulada» lo libera. Todo se vuelve a validar al guardar."
      onCerrar={onCerrar}
      bloqueado={ocupado}
    >
      {abierto && idTitulo !== null ? (
        <ContenidoEditarMatricula
          matriculaId={idTitulo}
          matriculaInicial={matriculaInicial}
          onOcupado={setOcupado}
          onCerrar={onCerrar}
        />
      ) : null}
    </Dialogo>
  );
}

function ContenidoEditarMatricula({
  matriculaId,
  matriculaInicial,
  onOcupado,
  onCerrar,
}: {
  matriculaId: number;
  matriculaInicial: MatriculaDetalle | null;
  onOcupado: (v: boolean) => void;
  onCerrar: () => void;
}) {
  const router = useRouter();
  const { mostrar } = useAvisos();
  const { ejecutar } = useOperacionPanel();
  const [detalle, setDetalle] = useState<MatriculaDetalle | null>(matriculaInicial);
  const [cargando, setCargando] = useState(matriculaInicial === null);
  const [errorCarga, setErrorCarga] = useState<string | null>(null);

  useEffect(() => {
    if (matriculaInicial !== null) return;
    let vigente = true;
    void ejecutar<{ data: MatriculaDetalle }>(`academico/matriculas/${matriculaId}`).then((res) => {
      if (!vigente) return;
      setCargando(false);
      if (!res.ok) {
        setErrorCarga(res.error.message);
        return;
      }
      setDetalle(res.datos.data);
    });
    return () => {
      vigente = false;
    };
  }, [matriculaId, matriculaInicial, ejecutar]);

  if (cargando) {
    return (
      <div className="space-y-4 py-2" aria-busy="true" aria-label="Cargando datos de la matrícula">
        <div className="grid gap-4 sm:grid-cols-2">
          <div className="h-16 animate-pulse rounded-lg bg-panel-bg" />
          <div className="h-16 animate-pulse rounded-lg bg-panel-bg" />
        </div>
        <div className="grid gap-4 sm:grid-cols-2">
          <div className="h-16 animate-pulse rounded-lg bg-panel-bg" />
          <div className="h-16 animate-pulse rounded-lg bg-panel-bg" />
        </div>
        <div className="h-24 animate-pulse rounded-lg bg-panel-bg" />
      </div>
    );
  }

  if (errorCarga || !detalle) {
    return (
      <div className="space-y-4">
        <Alerta tipo="error">{errorCarga ?? 'No se pudo cargar la matrícula.'}</Alerta>
        <div className="pie-acciones">
          <Boton variante="secundario" onClick={onCerrar}>Cerrar</Boton>
        </div>
      </div>
    );
  }

  if (detalle.eliminado) {
    return (
      <div className="space-y-4">
        <Alerta tipo="aviso">Esta matrícula está eliminada. Restáurala antes de editarla.</Alerta>
        <div className="pie-acciones">
          <Boton variante="secundario" onClick={onCerrar}>Cerrar</Boton>
        </div>
      </div>
    );
  }

  return (
    <FormularioMatricula
      enModal
      matricula={detalle}
      onOcupado={onOcupado}
      onCancelar={onCerrar}
      onGuardado={({ mensaje }) => {
        onCerrar();
        mostrar('exito', mensaje);
        router.refresh();
      }}
    />
  );
}
