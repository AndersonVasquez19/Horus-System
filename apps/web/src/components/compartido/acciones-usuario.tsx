'use client';

import { Ban, History, LogOut, Mail, Pencil, RotateCcw, Trash2, Unlock, UserPlus, UserX } from 'lucide-react';
import { useRouter } from 'next/navigation';
import { useState } from 'react';
import { usePuede } from '@/components/shared/sesion-panel-contexto';
import { useOperacionPanel } from '@/components/shared/use-operacion-panel';
import { Alerta } from '@/components/shared/ui/alerta';
import { useAvisos } from '@/components/shared/ui/avisos';
import { Boton, type TonoUi } from '@/components/shared/ui/boton';
import { Campo } from '@/components/shared/ui/campo';
import { Dialogo, DialogoConfirmacion } from '@/components/shared/ui/dialogo';
import { MenuFila, type ElementoMenuFila } from '@/components/shared/ui/menu-fila';
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
      <Dialogo abierto={abierto} lado="derecha" titulo="Registrar usuario" descripcion="La cuenta queda con el correo verificado y puede entrar de inmediato." onCerrar={() => setAbierto(false)} bloqueado={ocupado}>
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
  const puedeAuditoria = usePuede('auditoria.ver');
  const [editando, setEditando] = useState(false);
  const [ocupado, setOcupado] = useState(false);
  const [desactivando, setDesactivando] = useState(false);
  const [eliminando, setEliminando] = useState(false);
  const [suspendiendo, setSuspendiendo] = useState(false);
  const [cerrandoSesiones, setCerrandoSesiones] = useState(false);
  const [invitando, setInvitando] = useState(false);
  const [diasSuspension, setDiasSuspension] = useState('30');
  const [motivoSuspension, setMotivoSuspension] = useState('');
  const [error, setError] = useState<string | null>(null);

  /**
   * `avisarError`: para acciones lanzadas directo desde el menú, sin diálogo donde mostrar el error en línea;
   * sin él, un fallo no se vería en ninguna parte.
   */
  async function ejecutarAccion(ruta: string, metodo: 'POST' | 'DELETE' = 'POST', cuerpo?: Record<string, unknown>, avisarError = false) {
    setOcupado(true);
    setError(null);
    const resultado = await ejecutar<{ message: string }>(ruta, metodo, cuerpo);
    setOcupado(false);
    if (!resultado.ok) {
      setError(resultado.error.message);
      if (avisarError) mostrar('error', resultado.error.message);
      return false;
    }
    mostrar('exito', resultado.datos.message);
    router.refresh();
    return true;
  }

  async function eliminar() {
    if (await ejecutarAccion(`compartido/usuarios/${usuario.id}`, 'DELETE')) {
      setEliminando(false);
    }
  }

  const verActividad: ElementoMenuFila[] = puedeAuditoria
    ? [{ tipo: 'enlace', etiqueta: 'Ver actividad', icono: History, href: `/admin/${tono}/auditoria?usuario=${encodeURIComponent(usuario.email)}` }]
    : [];

  // Orden: consultar → editar → seguridad de la sesión → destructivas (el menú las separa y las pone al final).
  const elementos: ElementoMenuFila[] = usuario.eliminado
    ? [
        ...verActividad,
        ...(puedeEliminar
          ? [{
              tipo: 'accion' as const,
              etiqueta: 'Restaurar usuario',
              icono: RotateCcw,
              onClick: () => void ejecutarAccion(`compartido/usuarios/${usuario.id}/restaurar`, 'POST', undefined, true),
            }]
          : []),
      ]
    : [
        ...verActividad,
        { tipo: 'copiar', etiqueta: 'Copiar correo', valor: usuario.email, aviso: 'Correo copiado.' },
        ...(puedeEditar ? [{ tipo: 'accion' as const, etiqueta: 'Editar', icono: Pencil, onClick: () => setEditando(true) }] : []),
        ...(puedeEditar && !usuario.suspendido
          ? [{ tipo: 'accion' as const, etiqueta: 'Enviar enlace de acceso', icono: Mail, onClick: () => { setError(null); setInvitando(true); } }]
          : []),
        ...(puedeEditar
          ? [{ tipo: 'accion' as const, etiqueta: 'Cerrar sus sesiones', icono: LogOut, onClick: () => { setError(null); setCerrandoSesiones(true); } }]
          : []),
        ...(puedeEditar && usuario.suspendido
          ? [{
              tipo: 'accion' as const,
              etiqueta: 'Levantar suspensión',
              icono: Unlock,
              onClick: () => void ejecutarAccion(`compartido/usuarios/${usuario.id}/desbloquear`, 'POST', undefined, true),
            }]
          : []),
        ...(puedeEditar && !usuario.suspendido
          ? [{ tipo: 'accion' as const, etiqueta: 'Suspender cuenta', icono: Ban, peligro: true, onClick: () => { setError(null); setSuspendiendo(true); } }]
          : []),
        ...(puedeEliminar ? [{ tipo: 'accion' as const, etiqueta: 'Dar de baja (reversible)', icono: UserX, peligro: true, onClick: () => { setError(null); setDesactivando(true); } }] : []),
        ...(puedeEliminar ? [{ tipo: 'accion' as const, etiqueta: 'Eliminar definitivamente', icono: Trash2, peligro: true, onClick: () => { setError(null); setEliminando(true); } }] : []),
      ];

  return (
    <div className="flex justify-end">
      <MenuFila etiqueta={`Acciones para ${usuario.nombre}`} elementos={elementos} />

      <Dialogo abierto={editando} lado="derecha" titulo="Editar usuario" onCerrar={() => setEditando(false)} bloqueado={ocupado}>
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
      <Dialogo
        abierto={suspendiendo}
        titulo="Suspender cuenta"
        descripcion={`Se impedirá el acceso y se cerrarán todas las sesiones activas de ${usuario.nombre}.`}
        onCerrar={() => { setSuspendiendo(false); setError(null); }}
        bloqueado={ocupado}
        ancho="sm"
      >
        <form
          onSubmit={(e) => {
            e.preventDefault();
            const dias = Math.max(1, Number.parseInt(diasSuspension, 10) || 30);
            void ejecutarAccion(`compartido/usuarios/${usuario.id}/suspender`, 'POST', {
              dias,
              ...(motivoSuspension.trim() !== '' ? { motivo: motivoSuspension.trim() } : {}),
            }).then((ok) => {
              if (ok) {
                setSuspendiendo(false);
                setMotivoSuspension('');
              }
            });
          }}
          className="space-y-4"
        >
          {error ? <Alerta tipo="error">{error}</Alerta> : null}
          <Campo tono={tono} etiqueta="Días de suspensión" type="number" min="1" max="3650" value={diasSuspension} onChange={(e) => setDiasSuspension(e.target.value)} required />
          <Campo tono={tono} etiqueta="Motivo (opcional)" maxLength={255} value={motivoSuspension} onChange={(e) => setMotivoSuspension(e.target.value)} />
          <div className="pie-acciones">
            <Boton tono={tono} variante="secundario" type="button" onClick={() => setSuspendiendo(false)} disabled={ocupado}>Cancelar</Boton>
            <Boton tono={tono} variante="peligro" type="submit" cargando={ocupado}>Suspender cuenta</Boton>
          </div>
        </form>
      </Dialogo>
      <DialogoConfirmacion
        abierto={invitando}
        titulo="Enviar enlace de acceso"
        texto={<>Se enviará un correo a <strong>{usuario.email}</strong> con un enlace para que <strong>{usuario.nombre}</strong> establezca su contraseña.</>}
        confirmar="Enviar enlace"
        tono={tono}
        cargando={ocupado}
        error={error}
        onConfirmar={() => {
          void ejecutarAccion(`compartido/usuarios/${usuario.id}/invitar`).then((ok) => {
            if (ok) setInvitando(false);
          });
        }}
        onCerrar={() => { setInvitando(false); setError(null); }}
      />
      <DialogoConfirmacion
        abierto={cerrandoSesiones}
        titulo="Cerrar sesiones de usuario"
        texto={<>Se cerrarán todas las sesiones web activas y se revocarán los tokens API de <strong>{usuario.nombre}</strong>.</>}
        confirmar="Cerrar sesiones"
        tono={tono}
        cargando={ocupado}
        error={error}
        onConfirmar={() => {
          void ejecutarAccion(`compartido/usuarios/${usuario.id}/cerrar-sesiones`).then((ok) => {
            if (ok) setCerrandoSesiones(false);
          });
        }}
        onCerrar={() => { setCerrandoSesiones(false); setError(null); }}
      />
      <DialogoConfirmacion
        abierto={desactivando}
        titulo="Dar de baja lógica"
        texto={<>Se desactivará la cuenta de <strong>{usuario.nombre}</strong> ({usuario.email}) y se cerrarán sus sesiones. Podrás restaurarla desde la vista de dados de baja.</>}
        confirmar="Dar de baja"
        peligro
        tono={tono}
        cargando={ocupado}
        error={error}
        onConfirmar={() => {
          void ejecutarAccion(`compartido/usuarios/${usuario.id}/desactivar`).then((ok) => {
            if (ok) setDesactivando(false);
          });
        }}
        onCerrar={() => {
          setDesactivando(false);
          setError(null);
        }}
      />
      <DialogoConfirmacion
        abierto={eliminando}
        titulo="Eliminar usuario"
        texto={<>Se eliminará permanentemente la cuenta de <strong>{usuario.nombre}</strong> ({usuario.email}). Esta acción no se puede deshacer.</>}
        confirmar="Eliminar"
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
