import Link from 'next/link';
import { Suspense } from 'react';
import { EncabezadoPagina } from '@/components/shared/ui/encabezado-pagina';
import { EstadoError, EstadoProhibido, EstadoVacio } from '@/components/shared/ui/estados';
import { Insignia } from '@/components/shared/ui/insignia';
import { Paginacion, type MetaListado } from '@/components/shared/ui/paginacion';
import { EsqueletoListado } from '@/components/shared/ui/tabla';
import type { TonoUi } from '@/components/shared/ui/boton';
import type { Notificacion } from '@/features/notificaciones/tipos';
import { consulta, consultarPanel } from '@/lib/panel/api-servidor';
import { BotonMarcarLeida, BotonMarcarTodas } from './acciones-notificacion';

type Parametros = Record<string, string | string[] | undefined>;

const COLOR_TIPO: Record<string, 'verde' | 'ambar' | 'rojo' | 'azul'> = { success: 'verde', warning: 'ambar', danger: 'rojo' };

/** Bandeja de notificaciones del usuario. La monta cada panel bajo su propia ruta: `/admin/<panel>/notificaciones`. */
export function PaginaNotificaciones({ tono, searchParams }: { tono: TonoUi; searchParams: Promise<Parametros> }) {
  const base = `/admin/${tono}`;
  return (
    <>
      <EncabezadoPagina
        titulo="Notificaciones"
        descripcion="Avisos internos dirigidos a ti y avisos generales del equipo."
        migas={[{ etiqueta: 'Inicio', href: base }, { etiqueta: 'Notificaciones' }]}
      />
      <Suspense fallback={<EsqueletoListado etiqueta="Cargando notificaciones" />}>
        <Bandeja tono={tono} searchParams={searchParams} />
      </Suspense>
    </>
  );
}

async function Bandeja({ tono, searchParams }: { tono: TonoUi; searchParams: Promise<Parametros> }) {
  const ruta = `/admin/${tono}/notificaciones`;
  const cadena = consulta(await searchParams, ['page']);
  const resultado = await consultarPanel<{ data: Notificacion[]; meta: MetaListado & { pendientes: number } }>(`compartido/notificaciones${cadena}`, `${ruta}${cadena}`);

  if (resultado.estado === 'prohibido') return <EstadoProhibido volver={`/admin/${tono}`} />;
  if (resultado.estado !== 'ok') return <EstadoError texto="Inténtalo de nuevo en unos minutos." />;

  const { data: notificaciones, meta } = resultado.datos;
  if (notificaciones.length === 0) return <EstadoVacio titulo="No tienes notificaciones" texto="Cuando haya avisos para ti aparecerán aquí." />;

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <p className="text-sm text-panel-muted" aria-live="polite">
          {meta.pendientes === 0 ? 'No tienes avisos sin leer.' : `${meta.pendientes} sin leer`}
        </p>
        <BotonMarcarTodas tono={tono} pendientes={meta.pendientes} />
      </div>

      <ul className="divide-y divide-panel-border overflow-hidden rounded-xl border border-panel-border bg-panel-surface shadow-sm">
        {notificaciones.map((notificacion) => (
          <li key={notificacion.id} className="flex flex-wrap items-start justify-between gap-3 px-5 py-4">
            <div className="min-w-0 space-y-1">
              <p className={`flex items-center gap-2 text-sm ${notificacion.leida ? 'text-panel-muted' : 'font-semibold text-panel-text'}`}>
                {notificacion.leida ? null : <span className="sr-only">Sin leer: </span>}
                {notificacion.titulo}
                {notificacion.tipo && COLOR_TIPO[notificacion.tipo] ? <Insignia color={COLOR_TIPO[notificacion.tipo]}>{notificacion.tipo}</Insignia> : null}
              </p>
              {notificacion.mensaje ? <p className="text-sm text-panel-muted">{notificacion.mensaje}</p> : null}
              <p className="text-xs text-panel-muted">{notificacion.fecha_local ?? '—'}</p>
              {notificacion.vinculo ? (
                <Link href={notificacion.vinculo} className="text-xs font-semibold text-panel-text underline">
                  Abrir
                </Link>
              ) : null}
            </div>
            {notificacion.leida ? null : <BotonMarcarLeida id={notificacion.id} tono={tono} />}
          </li>
        ))}
      </ul>

      <Paginacion meta={meta} ruta={ruta} parametros={{}} />
    </div>
  );
}
