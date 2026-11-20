'use client';

import { useMemo, useState } from 'react';
import { useSesionPanel } from '@/components/shared/sesion-panel-contexto';
import { useFormularioPanel } from '@/components/shared/use-formulario-panel';
import { useOperacionPanel } from '@/components/shared/use-operacion-panel';
import { Alerta } from '@/components/shared/ui/alerta';
import { Boton, type TonoUi } from '@/components/shared/ui/boton';
import { Campo } from '@/components/shared/ui/campo';
import { Casilla } from '@/components/shared/ui/casilla';
import type { RolPanel } from '@/features/notificaciones/tipos';
import { esquemaRol } from '@/lib/panel/esquemas';
import { agruparPermisos } from '@/lib/permisos-etiquetas';

interface FormularioRolProps {
  /** Sin rol: alta. Con rol: edición. */
  rol?: RolPanel;
  /** Valores precargados al duplicar un rol existente sin asignarle id. */
  inicial?: { nombre: string; permisos: readonly string[] };
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
export function FormularioRol({ rol, inicial, permisos, tono, onGuardado, onCancelar, onOcupado }: FormularioRolProps) {
  const sesion = useSesionPanel();
  const { ejecutar } = useOperacionPanel();
  const esAdmin = sesion.roles.includes('admin');
  const misPermisos = useMemo(() => new Set(sesion.permisos), [sesion.permisos]);
  const [busqueda, setBusqueda] = useState('');

  const f = useFormularioPanel({
    esquema: esquemaRol,
    valores: {
      name: rol?.nombre ?? inicial?.nombre ?? '',
      permissions: (() => {
        const origen = rol?.permisos ?? inicial?.permisos ?? [];
        return esAdmin ? [...origen] : origen.filter((p) => misPermisos.has(p));
      })(),
    },
    onOcupado,
    enviar: (datos) => {
      const cuerpo = { name: datos.name, permissions: datos.permissions };
      return rol
        ? ejecutar<{ message: string }>(`compartido/roles/${rol.id}`, 'PUT', cuerpo)
        : ejecutar<{ message: string }>('compartido/roles', 'POST', cuerpo);
    },
    onExito: (respuesta) => onGuardado(respuesta.message),
  });
  const seleccion = f.form.watch('permissions');
  const grupos = useMemo(() => agruparPermisos(permisos), [permisos]);
  const gruposFiltrados = useMemo(() => {
    const q = busqueda.trim().toLowerCase();
    if (!q) return grupos;
    return grupos
      .map((g) => ({
        ...g,
        permisos: g.modulo.toLowerCase().includes(q) || g.etiqueta.toLowerCase().includes(q)
          ? g.permisos
          : g.permisos.filter((p) => p.clave.toLowerCase().includes(q) || p.etiqueta.toLowerCase().includes(q)),
      }))
      .filter((g) => g.permisos.length > 0);
  }, [grupos, busqueda]);

  const puedeConceder = (clave: string) => esAdmin || misPermisos.has(clave);

  const alternarGrupo = (claves: string[]) => {
    const permitidas = claves.filter(puedeConceder);
    if (permitidas.length === 0) return;
    const actual = f.form.getValues('permissions');
    f.form.setValue('permissions', permitidas.every((c) => actual.includes(c)) ? actual.filter((p) => !permitidas.includes(p)) : [...new Set([...actual, ...permitidas])], { shouldDirty: true });
  };

  const alternar = (permiso: string) => {
    if (!puedeConceder(permiso)) return;
    const actual = f.form.getValues('permissions');
    f.form.setValue('permissions', actual.includes(permiso) ? actual.filter((p) => p !== permiso) : [...actual, permiso], { shouldDirty: true });
  };

  return (
    <form onSubmit={f.enviar} noValidate className="space-y-4">
      {f.errorGeneral ? <Alerta tipo="error">{f.errorGeneral}</Alerta> : null}
      {rol && rol.usuarios > 0 ? (
        <Alerta tipo="aviso">
          Los cambios en este rol afectarán a {rol.usuarios === 1 ? '1 persona' : `${rol.usuarios} personas`} asignadas actualmente.
        </Alerta>
      ) : null}
      <Campo tono={tono} etiqueta="Nombre del rol" {...f.registro('name')} error={f.error('name')} required maxLength={255} autoComplete="off" />

      <fieldset className="space-y-3">
        <div className="flex flex-wrap items-center justify-between gap-2">
          <legend className="text-sm font-medium text-panel-text">Permisos ({seleccion.length})</legend>
          <input
            type="search"
            value={busqueda}
            onChange={(e) => setBusqueda(e.target.value)}
            placeholder="Filtrar permisos…"
            aria-label="Filtrar permisos"
            className="w-full rounded-lg border border-panel-border bg-panel-surface px-2.5 py-1.5 text-xs text-panel-text outline-none focus:border-academico-700 sm:w-52"
          />
        </div>
        <div className="max-h-80 space-y-4 overflow-y-auto rounded-lg border border-panel-border bg-panel-bg p-3">
          {gruposFiltrados.length === 0 ? (
            <p className="text-xs text-panel-muted">Ningún permiso coincide con «{busqueda}».</p>
          ) : gruposFiltrados.map((grupo) => {
            const clavesConcedibles = grupo.permisos.map((p) => p.clave).filter(puedeConceder);
            const todos = clavesConcedibles.length > 0 && clavesConcedibles.every((c) => seleccion.includes(c));
            return (
              <div key={grupo.modulo}>
                <div className="mb-1 flex items-center justify-between gap-2">
                  <p className="text-sm font-semibold text-panel-text">{grupo.etiqueta}</p>
                  {clavesConcedibles.length > 0 ? (
                    <button type="button" onClick={() => alternarGrupo(clavesConcedibles)} className="text-xs font-medium text-panel-muted underline hover:text-panel-text">
                      {todos ? 'Quitar todos' : 'Marcar todos'}
                    </button>
                  ) : null}
                </div>
                <div className="grid gap-x-4 gap-y-1 sm:grid-cols-2">
                  {grupo.permisos.map((permiso) => {
                    const bloqueado = !puedeConceder(permiso.clave);
                    return (
                      <Casilla
                        key={permiso.clave}
                        compacta
                        etiqueta={permiso.etiqueta}
                        checked={seleccion.includes(permiso.clave)}
                        disabled={bloqueado}
                        onChange={() => alternar(permiso.clave)}
                      />
                    );
                  })}
                </div>
              </div>
            );
          })}
        </div>
        {f.errorLista('permissions') ? <p role="alert" className="text-sm text-estado-error">{f.errorLista('permissions')}</p> : null}
      </fieldset>

      <div className="pie-acciones">
        <Boton tono={tono} variante="secundario" onClick={onCancelar} disabled={f.guardando}>Cancelar</Boton>
        <Boton tono={tono} type="submit" cargando={f.guardando}>{rol ? 'Guardar cambios' : 'Crear rol'}</Boton>
      </div>
    </form>
  );
}
