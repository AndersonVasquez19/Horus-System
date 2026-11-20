'use client';

import { useMemo, useState, type FormEvent } from 'react';
import { useSesionPanel } from '@/components/shared/sesion-panel-contexto';
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
  const [nombre, setNombre] = useState(rol?.nombre ?? inicial?.nombre ?? '');
  const [seleccion, setSeleccion] = useState<string[]>(() => {
    const origen = rol?.permisos ?? inicial?.permisos ?? [];
    return esAdmin ? [...origen] : origen.filter((p) => misPermisos.has(p));
  });
  const [busqueda, setBusqueda] = useState('');
  const [error, setError] = useState<ErrorPanel | null>(null);
  const [guardando, setGuardando] = useState(false);
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
    setSeleccion((actual) => (permitidas.every((c) => actual.includes(c)) ? actual.filter((p) => !permitidas.includes(p)) : [...new Set([...actual, ...permitidas])]));
  };

  const alternar = (permiso: string) => {
    if (!puedeConceder(permiso)) return;
    setSeleccion((actual) => (actual.includes(permiso) ? actual.filter((p) => p !== permiso) : [...actual, permiso]));
  };

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
      {rol && rol.usuarios > 0 ? (
        <Alerta tipo="aviso">
          Los cambios en este rol afectarán a {rol.usuarios === 1 ? '1 persona' : `${rol.usuarios} personas`} asignadas actualmente.
        </Alerta>
      ) : null}
      <Campo tono={tono} etiqueta="Nombre del rol" value={nombre} onChange={(e) => setNombre(e.target.value)} error={errorDeCampo(error, 'name')} required maxLength={255} autoComplete="off" />

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
                      <label key={permiso.clave} className={`inline-flex items-center gap-2 text-sm ${bloqueado ? 'cursor-not-allowed text-panel-muted opacity-60' : 'text-panel-text'}`}>
                        <input
                          type="checkbox"
                          checked={seleccion.includes(permiso.clave)}
                          disabled={bloqueado}
                          onChange={() => alternar(permiso.clave)}
                          className="h-4 w-4 rounded border-panel-border"
                        />
                        <span>{permiso.etiqueta}</span>
                      </label>
                    );
                  })}
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
