'use client';

import { useMemo } from 'react';
import { usePuede } from '@/components/shared/sesion-panel-contexto';
import { useFormularioPanel } from '@/components/shared/use-formulario-panel';
import { useOperacionPanel } from '@/components/shared/use-operacion-panel';
import { Alerta } from '@/components/shared/ui/alerta';
import { Boton, type TonoUi } from '@/components/shared/ui/boton';
import { Campo, CampoClave } from '@/components/shared/ui/campo';
import { Casilla } from '@/components/shared/ui/casilla';
import type { UsuarioPanel } from '@/features/notificaciones/tipos';
import { crearEsquemaUsuario, cuerpoUsuario } from '@/lib/panel/esquemas';

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
 * y, al editar, solo entonces se envía el campo `roles` (su ausencia no significa «quitar todos»). Los campos usan los
 * nombres de Laravel (`name`, `password`…) para que cada error del servidor caiga en su campo.
 */
export function FormularioUsuario({ usuario, roles, tono, onGuardado, onCancelar, onOcupado }: FormularioUsuarioProps) {
  const { ejecutar } = useOperacionPanel();
  const puedeAsignar = usePuede('usuarios.asignar-roles');
  const edicion = usuario !== undefined;
  const esquema = useMemo(() => crearEsquemaUsuario(edicion), [edicion]);

  const f = useFormularioPanel({
    esquema,
    valores: {
      name: usuario?.nombre ?? '',
      email: usuario?.email ?? '',
      password: '',
      password_confirmation: '',
      temporal: !usuario,
      roles: usuario?.roles ?? [],
    },
    onOcupado,
    enviar: (datos) => {
      const cuerpo = cuerpoUsuario(datos, { edicion, puedeAsignarRoles: puedeAsignar });
      return usuario
        ? ejecutar<{ message: string }>(`compartido/usuarios/${usuario.id}`, 'PUT', cuerpo)
        : ejecutar<{ message: string }>('compartido/usuarios', 'POST', cuerpo);
    },
    onExito: (respuesta) => onGuardado(respuesta.message),
  });

  const clave = f.form.watch('password');
  const seleccion = f.form.watch('roles');
  const alternar = (rol: string) =>
    f.form.setValue('roles', seleccion.includes(rol) ? seleccion.filter((nombreRol) => nombreRol !== rol) : [...seleccion, rol], { shouldDirty: true });

  return (
    <form onSubmit={f.enviar} noValidate className="space-y-4">
      {f.errorGeneral ? <Alerta tipo="error">{f.errorGeneral}</Alerta> : null}
      <div className="grid gap-4 sm:grid-cols-2">
        <Campo tono={tono} etiqueta="Nombre" {...f.registro('name')} error={f.error('name')} required maxLength={255} autoComplete="off" />
        <Campo tono={tono} etiqueta="Correo electrónico" type="email" {...f.registro('email')} error={f.error('email')} required maxLength={255} autoComplete="off" />
        <CampoClave
          tono={tono}
          etiqueta={usuario ? 'Nueva contraseña (opcional)' : 'Contraseña'}
          {...f.registro('password')}
          error={f.error('password')}
          required={!usuario}
          politica={!usuario}
          ayuda={usuario ? 'Déjala vacía para conservar la actual. Al cambiarla se cierran las demás sesiones de esa persona.' : undefined}
          autoComplete="new-password"
        />
        <CampoClave
          tono={tono}
          etiqueta="Confirmar contraseña"
          {...f.registro('password_confirmation')}
          error={f.error('password_confirmation')}
          required={!usuario}
          autoComplete="new-password"
        />
      </div>

      {!usuario || clave !== '' ? (
        <Casilla etiqueta="Contraseña temporal (exigir que el usuario la cambie en su próximo inicio de sesión)" {...f.registro('temporal')} />
      ) : null}

      {puedeAsignar ? (
        <fieldset className="space-y-1">
          <legend className="text-sm font-medium text-panel-text">Roles</legend>
          <div className="flex flex-wrap gap-x-5">
            {roles.map((rol) => (
              <Casilla key={rol} etiqueta={rol} checked={seleccion.includes(rol)} onChange={() => alternar(rol)} />
            ))}
          </div>
          {f.errorLista('roles') ? <p role="alert" className="text-sm text-estado-error">{f.errorLista('roles')}</p> : null}
        </fieldset>
      ) : null}

      <div className="pie-acciones">
        <Boton tono={tono} variante="secundario" onClick={onCancelar} disabled={f.guardando}>Cancelar</Boton>
        <Boton tono={tono} type="submit" cargando={f.guardando}>{usuario ? 'Guardar cambios' : 'Crear usuario'}</Boton>
      </div>
    </form>
  );
}
