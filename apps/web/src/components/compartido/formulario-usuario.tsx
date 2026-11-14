'use client';

import { useState, type FormEvent } from 'react';
import { usePuede } from '@/components/shared/sesion-panel-contexto';
import { useOperacionPanel } from '@/components/shared/use-operacion-panel';
import { Alerta } from '@/components/shared/ui/alerta';
import { Boton, type TonoUi } from '@/components/shared/ui/boton';
import { Campo } from '@/components/shared/ui/campo';
import type { UsuarioPanel } from '@/features/notificaciones/tipos';
import { errorDeCampo } from '@/lib/panel/cliente';
import type { ErrorPanel } from '@/lib/panel/tipos';

interface FormularioUsuarioProps {
  /** Sin usuario: alta. Con usuario: edición. */
  usuario?: UsuarioPanel;
  /** Nombres de rol que se pueden elegir (Laravel decide cuáles puede conceder quien actúa). */
  roles: readonly string[];
  tono: TonoUi;
  onGuardado: (mensaje: string) => void;
  onCancelar: () => void;
  onOcupado?: (ocupado: boolean) => void;
}

/**
 * Alta y edición de usuarios. Laravel aplica todas las reglas (correo único, clave segura, escalada de privilegios,
 * último administrador); aquí solo se muestran sus errores. La sección de roles aparece con `usuarios.asignar-roles`
 * y, al editar, solo entonces se envía el campo `roles` (su ausencia no significa «quitar todos»).
 */
export function FormularioUsuario({ usuario, roles, tono, onGuardado, onCancelar, onOcupado }: FormularioUsuarioProps) {
  const { ejecutar } = useOperacionPanel();
  const puedeAsignar = usePuede('usuarios.asignar-roles');
  const [nombre, setNombre] = useState(usuario?.nombre ?? '');
  const [email, setEmail] = useState(usuario?.email ?? '');
  const [clave, setClave] = useState('');
  const [confirmacion, setConfirmacion] = useState('');
  const [seleccion, setSeleccion] = useState<string[]>(usuario?.roles ?? []);
  const [error, setError] = useState<ErrorPanel | null>(null);
  const [guardando, setGuardando] = useState(false);

  const alternar = (rol: string) => setSeleccion((actual) => (actual.includes(rol) ? actual.filter((nombreRol) => nombreRol !== rol) : [...actual, rol]));

  async function enviar(evento: FormEvent<HTMLFormElement>) {
    evento.preventDefault();
    setGuardando(true);
    onOcupado?.(true);
    setError(null);

    const cuerpo: Record<string, unknown> = { name: nombre.trim(), email: email.trim() };
    if (clave !== '' || !usuario) {
      cuerpo.password = clave;
      cuerpo.password_confirmation = confirmacion;
    }
    if (puedeAsignar) cuerpo.roles = seleccion;

    const resultado = usuario
      ? await ejecutar<{ message: string }>(`compartido/usuarios/${usuario.id}`, 'PUT', cuerpo)
      : await ejecutar<{ message: string }>('compartido/usuarios', 'POST', cuerpo);
    setGuardando(false);
    onOcupado?.(false);

    if (!resultado.ok) {
      setError(resultado.error);
      return;
    }
    onGuardado(resultado.datos.message);
  }

  const err = (campo: string) => errorDeCampo(error, campo);
  const errorRoles = err('roles') ?? Object.keys(error?.errors ?? {}).filter((campo) => campo.startsWith('roles.')).map((campo) => err(campo)).find(Boolean);

  return (
    <form onSubmit={enviar} noValidate className="space-y-4">
      {error && error.code !== 'validacion' ? <Alerta tipo="error">{error.message}</Alerta> : null}
      <div className="grid gap-4 sm:grid-cols-2">
        <Campo tono={tono} etiqueta="Nombre" value={nombre} onChange={(e) => setNombre(e.target.value)} error={err('name')} required maxLength={255} autoComplete="off" />
        <Campo tono={tono} etiqueta="Correo electrónico" type="email" value={email} onChange={(e) => setEmail(e.target.value)} error={err('email')} required maxLength={255} autoComplete="off" />
        <Campo
          tono={tono}
          etiqueta={usuario ? 'Nueva contraseña (opcional)' : 'Contraseña'}
          type="password"
          value={clave}
          onChange={(e) => setClave(e.target.value)}
          error={err('password')}
          required={!usuario}
          ayuda={usuario ? 'Déjala vacía para conservar la actual. Al cambiarla se cierran las demás sesiones de esa persona.' : undefined}
          autoComplete="new-password"
        />
        <Campo tono={tono} etiqueta="Confirmar contraseña" type="password" value={confirmacion} onChange={(e) => setConfirmacion(e.target.value)} required={!usuario} autoComplete="new-password" />
      </div>

      {puedeAsignar ? (
        <fieldset className="space-y-2">
          <legend className="text-sm font-medium text-panel-text">Roles</legend>
          <div className="flex flex-wrap gap-x-5 gap-y-2">
            {roles.map((rol) => (
              <label key={rol} className="inline-flex items-center gap-2 text-sm text-panel-text">
                <input type="checkbox" checked={seleccion.includes(rol)} onChange={() => alternar(rol)} className="h-4 w-4 rounded border-panel-border" />
                {rol}
              </label>
            ))}
          </div>
          {errorRoles ? <p role="alert" className="text-sm text-estado-error">{errorRoles}</p> : null}
        </fieldset>
      ) : null}

      <div className="flex flex-col-reverse gap-2 border-t border-panel-border pt-4 sm:flex-row sm:justify-end">
        <Boton tono={tono} variante="secundario" onClick={onCancelar} disabled={guardando}>Cancelar</Boton>
        <Boton tono={tono} type="submit" cargando={guardando}>{usuario ? 'Guardar cambios' : 'Crear usuario'}</Boton>
      </div>
    </form>
  );
}
