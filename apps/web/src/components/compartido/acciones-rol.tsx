'use client';

import { Pencil, Plus, Trash2 } from 'lucide-react';
import { useRouter } from 'next/navigation';
import { useState } from 'react';
import { usePuede } from '@/components/shared/sesion-panel-contexto';
import { useOperacionPanel } from '@/components/shared/use-operacion-panel';
import { useAvisos } from '@/components/shared/ui/avisos';
import { Boton, type TonoUi } from '@/components/shared/ui/boton';
import { Dialogo, DialogoConfirmacion } from '@/components/shared/ui/dialogo';
import type { RolPanel } from '@/features/notificaciones/tipos';
import { FormularioRol } from './formulario-rol';

/** Botón «Nuevo rol»: abre el formulario en un diálogo. */
export function BotonNuevoRol({ permisos, tono }: { permisos: readonly string[]; tono: TonoUi }) {
  const puede = usePuede('roles.crear');
  const router = useRouter();
  const { mostrar } = useAvisos();
  const [abierto, setAbierto] = useState(false);
  const [ocupado, setOcupado] = useState(false);

  if (!puede) return null;

  return (
    <>
      <Boton tono={tono} icono={<Plus className="h-4 w-4" aria-hidden="true" />} onClick={() => setAbierto(true)}>
        Nuevo rol
      </Boton>
      <Dialogo abierto={abierto} titulo="Crear rol" descripcion="Elige solo los permisos que ese perfil necesita." onCerrar={() => setAbierto(false)} bloqueado={ocupado}>
        <FormularioRol
          permisos={permisos}
          tono={tono}
          onOcupado={setOcupado}
          onCancelar={() => setAbierto(false)}
          onGuardado={(mensaje) => {
            setAbierto(false);
            mostrar('exito', mensaje);
            router.refresh();
          }}
        />
      </Dialogo>
    </>
  );
}

/** Editar y eliminar un rol. Los roles `admin` y base no se eliminan (Laravel lo impide igual). */
export function AccionesRol({ rol, permisos, tono }: { rol: RolPanel; permisos: readonly string[]; tono: TonoUi }) {
  const router = useRouter();
  const { mostrar } = useAvisos();
  const { ejecutar } = useOperacionPanel();
  const puedeEditar = usePuede('roles.editar');
  const puedeEliminar = usePuede('roles.eliminar') && rol.tipo === 'personalizado';
  const [editando, setEditando] = useState(false);
  const [ocupado, setOcupado] = useState(false);
  const [eliminando, setEliminando] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function eliminar() {
    setOcupado(true);
    setError(null);
    const resultado = await ejecutar<{ message: string }>(`compartido/roles/${rol.id}`, 'DELETE');
    setOcupado(false);
    if (!resultado.ok) {
      setError(resultado.error.errors ? Object.values(resultado.error.errors)[0]?.[0] ?? resultado.error.message : resultado.error.message);
      return;
    }
    setEliminando(false);
    mostrar('exito', resultado.datos.message);
    router.refresh();
  }

  if (!puedeEditar && !puedeEliminar) return null;

  return (
    <div className="flex flex-wrap gap-2">
      {puedeEditar ? (
        <Boton tono={tono} variante="secundario" icono={<Pencil className="h-4 w-4" aria-hidden="true" />} onClick={() => setEditando(true)}>
          Editar<span className="sr-only"> el rol {rol.nombre}</span>
        </Boton>
      ) : null}
      {puedeEliminar ? (
        <Boton tono={tono} variante="secundario" icono={<Trash2 className="h-4 w-4" aria-hidden="true" />} onClick={() => setEliminando(true)}>
          Eliminar<span className="sr-only"> el rol {rol.nombre}</span>
        </Boton>
      ) : null}

      <Dialogo abierto={editando} titulo={`Editar rol «${rol.nombre}»`} onCerrar={() => setEditando(false)} bloqueado={ocupado}>
        <FormularioRol
          rol={rol}
          permisos={permisos}
          tono={tono}
          onOcupado={setOcupado}
          onCancelar={() => setEditando(false)}
          onGuardado={(mensaje) => {
            setEditando(false);
            mostrar('exito', mensaje);
            router.refresh();
          }}
        />
      </Dialogo>
      <DialogoConfirmacion
        abierto={eliminando}
        titulo="Eliminar rol"
        texto={<>Se eliminará el rol <strong>{rol.nombre}</strong>. Esta acción no se puede deshacer.</>}
        confirmar="Eliminar rol"
        peligro
        tono={tono}
        cargando={ocupado}
        error={error}
        onConfirmar={eliminar}
        onCerrar={() => {
          setEliminando(false);
          setError(null);
        }}
      />
    </div>
  );
}
