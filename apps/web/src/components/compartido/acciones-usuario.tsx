'use client';

import { Pencil, Trash2, UserPlus } from 'lucide-react';
import { useRouter } from 'next/navigation';
import { useState } from 'react';
import { usePuede } from '@/components/shared/sesion-panel-contexto';
import { useOperacionPanel } from '@/components/shared/use-operacion-panel';
import { useAvisos } from '@/components/shared/ui/avisos';
import { Boton, type TonoUi } from '@/components/shared/ui/boton';
import { Dialogo, DialogoConfirmacion } from '@/components/shared/ui/dialogo';
import type { UsuarioPanel } from '@/features/notificaciones/tipos';
import { FormularioUsuario } from './formulario-usuario';

/** Botón «Nuevo usuario» del listado: abre el formulario en un diálogo. */
export function BotonNuevoUsuario({ roles, tono }: { roles: readonly string[]; tono: TonoUi }) {
  const puede = usePuede('usuarios.crear');
  const router = useRouter();
  const { mostrar } = useAvisos();
  const [abierto, setAbierto] = useState(false);
  const [ocupado, setOcupado] = useState(false);

  if (!puede) return null;

  return (
    <>
      <Boton tono={tono} icono={<UserPlus className="h-4 w-4" aria-hidden="true" />} onClick={() => setAbierto(true)}>
        Nuevo usuario
      </Boton>
      <Dialogo abierto={abierto} titulo="Registrar usuario" descripcion="La cuenta queda con el correo verificado y puede entrar de inmediato." onCerrar={() => setAbierto(false)} bloqueado={ocupado}>
        <FormularioUsuario
          roles={roles}
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

/** Editar y eliminar un usuario desde su fila del listado. */
export function AccionesUsuario({ usuario, roles, tono }: { usuario: UsuarioPanel; roles: readonly string[]; tono: TonoUi }) {
  const router = useRouter();
  const { mostrar } = useAvisos();
  const { ejecutar } = useOperacionPanel();
  const puedeEditar = usePuede('usuarios.editar');
  const puedeEliminar = usePuede('usuarios.eliminar');
  const [editando, setEditando] = useState(false);
  const [ocupado, setOcupado] = useState(false);
  const [eliminando, setEliminando] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function eliminar() {
    setOcupado(true);
    setError(null);
    const resultado = await ejecutar<{ message: string }>(`compartido/usuarios/${usuario.id}`, 'DELETE');
    setOcupado(false);
    if (!resultado.ok) {
      setError(resultado.error.message);
      return;
    }
    setEliminando(false);
    mostrar('exito', resultado.datos.message);
    router.refresh();
  }

  if (!puedeEditar && !puedeEliminar) return null;

  return (
    <div className="flex justify-end gap-2">
      {puedeEditar ? (
        <Boton tono={tono} variante="secundario" icono={<Pencil className="h-4 w-4" aria-hidden="true" />} onClick={() => setEditando(true)}>
          Editar<span className="sr-only"> a {usuario.nombre}</span>
        </Boton>
      ) : null}
      {puedeEliminar ? (
        <Boton tono={tono} variante="secundario" icono={<Trash2 className="h-4 w-4" aria-hidden="true" />} onClick={() => setEliminando(true)}>
          Eliminar<span className="sr-only"> a {usuario.nombre}</span>
        </Boton>
      ) : null}

      <Dialogo abierto={editando} titulo="Editar usuario" onCerrar={() => setEditando(false)} bloqueado={ocupado}>
        <FormularioUsuario
          usuario={usuario}
          roles={roles}
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
        titulo="Eliminar usuario"
        texto={<>Se eliminará la cuenta de <strong>{usuario.nombre}</strong> ({usuario.email}). Esta acción no se puede deshacer.</>}
        confirmar="Eliminar usuario"
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
