'use client';

import { ArchiveRestore, Archive, BookPlus, Pencil } from 'lucide-react';
import { useRouter } from 'next/navigation';
import { useState } from 'react';
import { usePuede } from '@/components/shared/sesion-panel-contexto';
import { useOperacionPanel } from '@/components/shared/use-operacion-panel';
import { useAvisos } from '@/components/shared/ui/avisos';
import { Boton } from '@/components/shared/ui/boton';
import { Dialogo, DialogoConfirmacion } from '@/components/shared/ui/dialogo';
import type { CursoDetalle } from '@/features/academico/tipos';
import { FormularioCurso } from './formulario-curso';

const LISTADO = '/admin/academico/cursos';

/** Botón «Nuevo curso» del listado: abre el formulario en un diálogo y lleva al detalle del curso creado. */
export function BotonNuevoCurso() {
  const puede = usePuede('cursos.crear');
  const router = useRouter();
  const { mostrar } = useAvisos();
  const [abierto, setAbierto] = useState(false);
  const [ocupado, setOcupado] = useState(false);

  if (!puede) return null;

  return (
    <>
      <Boton icono={<BookPlus className="h-4 w-4" aria-hidden="true" />} onClick={() => setAbierto(true)}>
        Nuevo curso
      </Boton>
      <Dialogo abierto={abierto} titulo="Registrar curso" descripcion="La ficha pública se autoriza aparte, desde el detalle del curso." onCerrar={() => setAbierto(false)} bloqueado={ocupado}>
        <FormularioCurso
          onOcupado={setOcupado}
          onCancelar={() => setAbierto(false)}
          onGuardado={(curso, mensaje) => {
            setAbierto(false);
            mostrar('exito', mensaje);
            router.push(`${LISTADO}/${curso.id}`);
          }}
        />
      </Dialogo>
    </>
  );
}

type Confirmacion = 'archivar' | 'restaurar' | null;

/** Acciones del detalle del curso: editar, archivar (eliminación lógica) y restaurar. */
export function AccionesCurso({ curso }: { curso: CursoDetalle }) {
  const router = useRouter();
  const { mostrar } = useAvisos();
  const { ejecutar } = useOperacionPanel();
  const puedeEditar = usePuede('cursos.editar');
  const puedeEliminar = usePuede('cursos.eliminar');
  const puedeRestaurar = usePuede('cursos.restaurar');
  const [editando, setEditando] = useState(false);
  const [ocupado, setOcupado] = useState(false);
  const [confirmacion, setConfirmacion] = useState<Confirmacion>(null);
  const [error, setError] = useState<string | null>(null);

  async function confirmar() {
    if (confirmacion === null) return;
    setOcupado(true);
    setError(null);
    const resultado = confirmacion === 'archivar'
      ? await ejecutar<{ message: string }>(`academico/cursos/${curso.id}`, 'DELETE')
      : await ejecutar<{ message: string }>(`academico/cursos/${curso.id}/restaurar`, 'POST');
    setOcupado(false);

    if (!resultado.ok) {
      setError(resultado.error.message);
      return;
    }
    setConfirmacion(null);
    mostrar('exito', resultado.datos.message);
    router.refresh();
  }

  const cerrarConfirmacion = () => {
    setConfirmacion(null);
    setError(null);
  };

  return (
    <>
      {curso.eliminado ? (
        puedeRestaurar ? (
          <Boton variante="secundario" icono={<ArchiveRestore className="h-4 w-4" aria-hidden="true" />} onClick={() => setConfirmacion('restaurar')}>
            Restaurar
          </Boton>
        ) : null
      ) : (
        <>
          {puedeEditar ? (
            <Boton variante="secundario" icono={<Pencil className="h-4 w-4" aria-hidden="true" />} onClick={() => setEditando(true)}>
              Editar
            </Boton>
          ) : null}
          {puedeEliminar ? (
            <Boton variante="secundario" icono={<Archive className="h-4 w-4" aria-hidden="true" />} onClick={() => setConfirmacion('archivar')}>
              Archivar
            </Boton>
          ) : null}
        </>
      )}

      <Dialogo abierto={editando} titulo="Editar curso" descripcion={`${curso.codigo} · ${curso.nombre}`} onCerrar={() => setEditando(false)} bloqueado={ocupado}>
        <FormularioCurso
          curso={curso}
          onOcupado={setOcupado}
          onCancelar={() => setEditando(false)}
          onGuardado={(_, mensaje) => {
            setEditando(false);
            mostrar('exito', mensaje);
            router.refresh();
          }}
        />
      </Dialogo>

      <DialogoConfirmacion
        abierto={confirmacion !== null}
        titulo={confirmacion === 'restaurar' ? '¿Restaurar el curso?' : '¿Archivar el curso?'}
        texto={
          confirmacion === 'restaurar' ? (
            <p>Volverá a aparecer en los listados y en las nuevas matrículas si su estado es «Activo».</p>
          ) : (
            <p>
              Dejará de aparecer en los listados y en las nuevas matrículas. Su historial se conserva y puede restaurarse. Si tiene
              matrículas vigentes, primero hay que anularlas.
            </p>
          )
        }
        confirmar={confirmacion === 'restaurar' ? 'Restaurar' : 'Archivar'}
        peligro={confirmacion === 'archivar'}
        cargando={ocupado}
        error={error}
        onConfirmar={confirmar}
        onCerrar={cerrarConfirmacion}
      />
    </>
  );
}
