'use client';

import { useMemo, useState, type FormEvent } from 'react';
import { useOperacionPanel } from '@/components/shared/use-operacion-panel';
import { Alerta } from '@/components/shared/ui/alerta';
import { Boton, type TonoUi } from '@/components/shared/ui/boton';
import { Campo } from '@/components/shared/ui/campo';
import type { RolPanel } from '@/features/notificaciones/tipos';
import { errorDeCampo } from '@/lib/panel/cliente';
import { agruparPermisos } from '@/lib/permisos-etiquetas';
import type { ErrorPanel } from '@/lib/panel/tipos';

interface FormularioRolProps {
  /** Sin rol: alta. Con rol: edición. */
  rol?: RolPanel;
  /** Catálogo de permisos asignables (sin los históricos que ya no corresponden a ningún módulo). */
  permisos: readonly string[];
  tono: TonoUi;
  onGuardado: (mensaje: string) => void;
  onCancelar: () => void;
  onOcupado?: (ocupado: boolean) => void;
}

/**
 * Alta y edición de roles. Laravel decide qué se puede conceder (quien no es administrador solo concede permisos
 * que ya posee) y protege el rol `admin` y los roles base; aquí solo se muestran sus errores.
 */
export function FormularioRol({ rol, permisos, tono, onGuardado, onCancelar, onOcupado }: FormularioRolProps) {
  const { ejecutar } = useOperacionPanel();
  const [nombre, setNombre] = useState(rol?.nombre ?? '');
  const [seleccion, setSeleccion] = useState<string[]>(rol?.permisos ?? []);
  const [error, setError] = useState<ErrorPanel | null>(null);
  const [guardando, setGuardando] = useState(false);
  const grupos = useMemo(() => agruparPermisos(permisos), [permisos]);

  const alternarGrupo = (claves: string[]) =>
    setSeleccion((actual) => (claves.every((c) => actual.includes(c)) ? actual.filter((p) => !claves.includes(p)) : [...new Set([...actual, ...claves])]));

  const alternar = (permiso: string) => setSeleccion((actual) => (actual.includes(permiso) ? actual.filter((p) => p !== permiso) : [...actual, permiso]));

  async function enviar(evento: FormEvent<HTMLFormElement>) {
    evento.preventDefault();
    setGuardando(true);
    onOcupado?.(true);
    setError(null);

    const cuerpo = { name: nombre.trim(), permissions: seleccion };
    const resultado = rol
      ? await ejecutar<{ message: string }>(`compartido/roles/${rol.id}`, 'PUT', cuerpo)
      : await ejecutar<{ message: string }>('compartido/roles', 'POST', cuerpo);
    setGuardando(false);
    onOcupado?.(false);

    if (!resultado.ok) {
      setError(resultado.error);
      return;
    }
    onGuardado(resultado.datos.message);
  }

  const errorPermisos = errorDeCampo(error, 'permissions') ?? Object.keys(error?.errors ?? {}).filter((campo) => campo.startsWith('permissions.')).map((campo) => errorDeCampo(error, campo)).find(Boolean);

  return (
    <form onSubmit={enviar} noValidate className="space-y-4">
      {error && error.code !== 'validacion' ? <Alerta tipo="error">{error.message}</Alerta> : null}
      <Campo tono={tono} etiqueta="Nombre del rol" value={nombre} onChange={(e) => setNombre(e.target.value)} error={errorDeCampo(error, 'name')} required maxLength={255} autoComplete="off" />

      <fieldset className="space-y-3">
        <legend className="text-sm font-medium text-panel-text">Permisos</legend>
        <div className="max-h-80 space-y-4 overflow-y-auto rounded-lg border border-panel-border bg-panel-bg p-3">
          {grupos.map((grupo) => {
            const claves = grupo.permisos.map((p) => p.clave);
            const todos = claves.every((c) => seleccion.includes(c));
            return (
              <div key={grupo.modulo}>
                <div className="mb-1 flex items-center justify-between gap-2">
                  <p className="text-sm font-semibold text-panel-text">{grupo.etiqueta}</p>
                  <button type="button" onClick={() => alternarGrupo(claves)} className="text-xs font-medium text-panel-muted underline hover:text-panel-text">
                    {todos ? 'Quitar todos' : 'Marcar todos'}
                  </button>
                </div>
                <div className="grid gap-x-4 gap-y-1 sm:grid-cols-2">
                  {grupo.permisos.map((permiso) => (
                    <label key={permiso.clave} className="inline-flex items-center gap-2 text-sm text-panel-text">
                      <input type="checkbox" checked={seleccion.includes(permiso.clave)} onChange={() => alternar(permiso.clave)} className="h-4 w-4 rounded border-panel-border" />
                      {permiso.etiqueta}
                    </label>
                  ))}
                </div>
              </div>
            );
          })}
        </div>
        {errorPermisos ? <p role="alert" className="text-sm text-estado-error">{errorPermisos}</p> : null}
      </fieldset>

      <div className="flex flex-col-reverse gap-2 border-t border-panel-border pt-4 sm:flex-row sm:justify-end">
        <Boton tono={tono} variante="secundario" onClick={onCancelar} disabled={guardando}>Cancelar</Boton>
        <Boton tono={tono} type="submit" cargando={guardando}>{rol ? 'Guardar cambios' : 'Crear rol'}</Boton>
      </div>
    </form>
  );
}
