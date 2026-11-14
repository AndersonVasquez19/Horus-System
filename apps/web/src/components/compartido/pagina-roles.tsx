import { Suspense } from 'react';
import { EncabezadoPagina } from '@/components/shared/ui/encabezado-pagina';
import { EstadoError, EstadoProhibido, EstadoVacio } from '@/components/shared/ui/estados';
import { Insignia } from '@/components/shared/ui/insignia';
import type { TonoUi } from '@/components/shared/ui/boton';
import type { RolPanel } from '@/features/notificaciones/tipos';
import { consultarPanel } from '@/lib/panel/api-servidor';
import { AccionesRol, BotonNuevoRol } from './acciones-rol';

const TIPOS = { principal: 'Principal', base: 'Base', personalizado: 'Personalizado' } as const;

/** Roles y permisos. La monta cada panel bajo `/admin/<panel>/roles`; Laravel decide qué puede hacer cada persona. */
export function PaginaRoles({ tono }: { tono: TonoUi }) {
  return (
    <>
      <EncabezadoPagina
        titulo="Roles y permisos"
        descripcion="Perfiles de acceso y los permisos que concede cada uno."
        migas={[{ etiqueta: 'Inicio', href: `/admin/${tono}` }, { etiqueta: 'Roles y permisos' }]}
      />
      <Suspense fallback={<div className="h-72 animate-pulse rounded-xl border border-panel-border bg-panel-surface" aria-busy="true" aria-label="Cargando los roles" />}>
        <Roles tono={tono} />
      </Suspense>
    </>
  );
}

async function Roles({ tono }: { tono: TonoUi }) {
  const resultado = await consultarPanel<{ data: RolPanel[]; meta: { permisos: string[] } }>('compartido/roles', `/admin/${tono}/roles`);

  if (resultado.estado === 'prohibido') return <EstadoProhibido volver={`/admin/${tono}`} />;
  if (resultado.estado !== 'ok') return <EstadoError texto="Inténtalo de nuevo en unos minutos." />;

  const { data: roles, meta } = resultado.datos;

  return (
    <div className="space-y-4">
      <div className="flex justify-end">
        <BotonNuevoRol permisos={meta.permisos} tono={tono} />
      </div>

      {roles.length === 0 ? (
        <EstadoVacio titulo="Todavía no hay roles" texto="Crea el primero con «Nuevo rol»." />
      ) : (
        <ul className="grid gap-4 lg:grid-cols-2">
          {roles.map((rol) => (
            <li key={rol.id} className="space-y-3 rounded-xl border border-panel-border bg-panel-surface p-5 shadow-sm">
              <div className="flex flex-wrap items-start justify-between gap-2">
                <div>
                  <h2 className="font-heading text-base font-semibold text-panel-text">{rol.nombre}</h2>
                  <p className="text-xs text-panel-muted">{rol.usuarios === 1 ? '1 usuario' : `${rol.usuarios} usuarios`} · {rol.permisos.length} permisos</p>
                </div>
                <Insignia color={rol.tipo === 'principal' ? 'azul' : 'gris'}>{TIPOS[rol.tipo]}</Insignia>
              </div>
              <p className="flex flex-wrap gap-1">
                {rol.permisos.length === 0 ? (
                  <span className="text-sm text-panel-muted">Sin permisos</span>
                ) : (
                  rol.permisos.map((permiso) => <span key={permiso} className="rounded-md bg-panel-bg px-2 py-0.5 font-mono text-xs text-panel-muted">{permiso}</span>)
                )}
              </p>
              <AccionesRol rol={rol} permisos={meta.permisos} tono={tono} />
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}
