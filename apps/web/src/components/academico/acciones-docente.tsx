'use client';

import { ArchiveRestore, Pencil, Trash2, UserPlus } from 'lucide-react';
import { useRouter } from 'next/navigation';
import { useState } from 'react';
import { usePuede } from '@/components/shared/sesion-panel-contexto';
import { useOperacionPanel } from '@/components/shared/use-operacion-panel';
import { useAvisos } from '@/components/shared/ui/avisos';
import { Boton } from '@/components/shared/ui/boton';
import { Dialogo, DialogoConfirmacion } from '@/components/shared/ui/dialogo';
import type { DocenteDetalle } from '@/features/academico/tipos';
import { FormularioDocente } from './formulario-docente';

const LISTADO = '/admin/academico/docentes';

/** Botón «Nuevo docente» del listado: abre el alta (con consulta de DNI) y lleva a la ficha creada. */
export function BotonNuevoDocente() {
  const puede = usePuede('docentes.crear');
  const router = useRouter();
  const { mostrar } = useAvisos();
  const [abierto, setAbierto] = useState(false);
  const [ocupado, setOcupado] = useState(false);
  if (!puede) return null;

  return (
    <>
      <Boton icono={<UserPlus className="h-4 w-4" aria-hidden="true" />} onClick={() => setAbierto(true)}>Nuevo docente</Boton>
      <Dialogo abierto={abierto} titulo="Registrar docente" descripcion="Se identifica solo por DNI peruano; los datos se consultan en RENIEC y se confirman antes de guardar." onCerrar={() => setAbierto(false)} bloqueado={ocupado}>
        <FormularioDocente
          onOcupado={setOcupado}
          onCancelar={() => setAbierto(false)}
          onGuardado={(docente, mensaje) => {
            setAbierto(false);
            mostrar('exito', mensaje);
            router.push(`${LISTADO}/${docente.identificador}`);
          }}
        />
      </Dialogo>
    </>
  );
}

type Confirmacion = 'eliminar' | 'restaurar' | null;

/** Acciones de la ficha del docente: editar, eliminar (baja lógica) y restaurar. */
export function AccionesDocente({ docente }: { docente: DocenteDetalle }) {
  const router = useRouter();
  const { mostrar } = useAvisos();
  const { ejecutar } = useOperacionPanel();
  const puedeEditar = usePuede('docentes.editar');
  const puedeEliminar = usePuede('docentes.eliminar');
  const puedeRestaurar = usePuede('docentes.restaurar');
  const [editando, setEditando] = useState(false);
  const [ocupado, setOcupado] = useState(false);
  const [confirmacion, setConfirmacion] = useState<Confirmacion>(null);
  const [error, setError] = useState<string | null>(null);

  async function confirmar() {
    if (confirmacion === null) return;
    setOcupado(true);
    setError(null);
    const resultado = confirmacion === 'eliminar'
      ? await ejecutar<{ message: string }>(`academico/docentes/${docente.identificador}`, 'DELETE')
      : await ejecutar<{ message: string }>(`academico/docentes/${docente.identificador}/restaurar`, 'POST');
    setOcupado(false);
    if (!resultado.ok) {
      setError(resultado.error.message);
      return;
    }
    setConfirmacion(null);
    mostrar('exito', resultado.datos.message);
    router.refresh();
  }

  return (
    <>
      {docente.eliminado ? (
        puedeRestaurar ? <Boton variante="secundario" icono={<ArchiveRestore className="h-4 w-4" aria-hidden="true" />} onClick={() => setConfirmacion('restaurar')}>Restaurar</Boton> : null
      ) : (
        <>
          {puedeEditar ? <Boton variante="secundario" icono={<Pencil className="h-4 w-4" aria-hidden="true" />} onClick={() => setEditando(true)}>Editar</Boton> : null}
          {puedeEliminar ? <Boton variante="secundario" icono={<Trash2 className="h-4 w-4" aria-hidden="true" />} onClick={() => setConfirmacion('eliminar')}>Eliminar</Boton> : null}
        </>
      )}

      <Dialogo abierto={editando} titulo="Editar docente" descripcion={`${docente.nombres} ${docente.apellidos}`} onCerrar={() => setEditando(false)} bloqueado={ocupado}>
        <FormularioDocente
          docente={docente}
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
        titulo={confirmacion === 'restaurar' ? '¿Restaurar al docente?' : '¿Eliminar al docente?'}
        texto={
          confirmacion === 'restaurar'
            ? <p>Volverá a aparecer en los listados y podrá asignarse a ediciones si su estado es «Activo».</p>
            : <p>Dejará de aparecer en los listados y no podrá asignarse a ediciones. Su historial y su DNI se conservan y puede restaurarse. Si está en ediciones vigentes, primero hay que quitarlo de ellas.</p>
        }
        confirmar={confirmacion === 'restaurar' ? 'Restaurar' : 'Eliminar'}
        peligro={confirmacion === 'eliminar'}
        cargando={ocupado}
        error={error}
        onConfirmar={confirmar}
        onCerrar={() => { setConfirmacion(null); setError(null); }}
      />
    </>
  );
}
