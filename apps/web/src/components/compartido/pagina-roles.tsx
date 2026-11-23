import Link from 'next/link';
import { Suspense } from 'react';
import { EncabezadoPagina } from '@/components/shared/ui/encabezado-pagina';
import { EstadoError, EstadoProhibido, EstadoVacio } from '@/components/shared/ui/estados';
import { Insignia } from '@/components/shared/ui/insignia';
import type { TonoUi } from '@/components/shared/ui/boton';
import type { RolPanel } from '@/features/notificaciones/tipos';
import { consultarPanel } from '@/lib/panel/api-servidor';
import { etiqueta } from '@/lib/panel/formato';
import { agruparPermisos } from '@/lib/permisos-etiquetas';
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
          {roles.map((rol) => {
            const grupos = agruparPermisos(rol.permisos);
            return (
              <li key={rol.id} className="space-y-3 rounded-xl border border-panel-border bg-panel-surface p-5 shadow-sm">
                <div className="flex flex-wrap items-start justify-between gap-2">
                  <div>
                    <h2 className="font-heading text-base font-semibold text-panel-text">{etiqueta('rol', rol.nombre)}</h2>
                    <p className="text-xs text-panel-muted">
                      <Link href={`/admin/${tono}/usuarios?rol=${encodeURIComponent(rol.nombre)}`} className="font-medium text-panel-text hover:underline">
                        {rol.usuarios === 1 ? '1 usuario' : `${rol.usuarios} usuarios`}
                      </Link>
                      {' · '}
                      {rol.permisos.length === 1 ? '1 permiso' : `${rol.permisos.length} permisos`}
                      {' · '}
                      <span className="font-mono">{rol.nombre}</span>
                    </p>
                  </div>
                  <Insignia color={rol.tipo === 'principal' ? 'azul' : 'gris'}>{TIPOS[rol.tipo]}</Insignia>
                </div>
                {rol.permisos.length === 0 ? (
                  <p className="text-sm text-panel-muted">Sin permisos</p>
                ) : (
                  <details className="rounded-lg border border-panel-border bg-panel-bg/60 p-3" open={rol.permisos.length <= 16}>
                    <summary className="cursor-pointer select-none text-xs font-semibold text-panel-text">
                      Ver permisos por módulo ({grupos.length} {grupos.length === 1 ? 'módulo' : 'módulos'})
                    </summary>
                    <dl className="mt-3 space-y-2.5">
                      {grupos.map((grupo) => (
                        <div key={grupo.modulo}>
                          <dt className="text-xs font-semibold text-panel-text">{grupo.etiqueta}</dt>
                          <dd className="mt-1 flex flex-wrap gap-1">
                            {grupo.permisos.map((permiso) => (
                              <span key={permiso.clave} className="rounded-md bg-panel-surface border border-panel-border px-2 py-0.5 text-xs text-panel-muted">{permiso.etiqueta}</span>
                            ))}
                          </dd>
                        </div>
                      ))}
                    </dl>
                  </details>
                )}
                <AccionesRol rol={rol} permisos={meta.permisos} tono={tono} />
              </li>
            );
          })}
        </ul>
      )}
    </div>
  );
}
