import 'server-only';

import Link from 'next/link';
import { cookies, headers } from 'next/headers';
import { Bell } from 'lucide-react';
import { COOKIE_NAVEGADOR, llamarLaravel } from '@/lib/panel/laravel';
import { ipDelCliente } from '@/lib/panel/red';
import type { TonoPanel } from './panel-tipos';

/** Por encima de este número la insignia muestra «99+» (el conteo exacto está en la bandeja). */
const TOPE_VISIBLE = 99;

const CLASE_ENLACE =
  'relative inline-flex items-center rounded-md p-2 text-panel-text outline-offset-2 hover:bg-panel-bg focus-visible:outline-2 focus-visible:outline-academico-700';

/**
 * Campana del panel: enlaza a la bandeja del propio panel y avisa de cuántas notificaciones siguen sin leer.
 * El conteo lo calcula Laravel por usuario (`meta.pendientes`: lo suyo más lo general que aún no leyó). Si Laravel no
 * responde, la campana sigue siendo un enlace a la bandeja, sin número: un fallo aquí nunca afecta al resto del panel.
 * Lee cookies: debe renderizarse dentro de un `<Suspense>`.
 */
export async function CampanaNotificaciones({ tono }: { tono: TonoPanel }) {
  const pendientes = await contarPendientes();
  return <EnlaceCampana tono={tono} pendientes={pendientes} />;
}

/** Sin conteo (mientras carga o si falla): mismo enlace, sin insignia. */
export function CampanaSinConteo({ tono }: { tono: TonoPanel }) {
  return <EnlaceCampana tono={tono} pendientes={null} />;
}

async function contarPendientes(): Promise<number | null> {
  const cookieSesion = (await cookies()).get(COOKIE_NAVEGADOR)?.value;
  if (!cookieSesion) return null;

  const respuesta = await llamarLaravel<{ meta?: { pendientes?: unknown } }>('compartido/notificaciones', {
    cookieSesion,
    ipCliente: ipDelCliente(await headers()),
  });
  if (!respuesta.ok) return null;

  const total = respuesta.datos.meta?.pendientes;
  return typeof total === 'number' && Number.isInteger(total) && total >= 0 ? total : null;
}

function EnlaceCampana({ tono, pendientes }: { tono: TonoPanel; pendientes: number | null }) {
  const etiqueta =
    pendientes === null ? 'Notificaciones' : pendientes === 0 ? 'Notificaciones, ninguna sin leer' : `Notificaciones, ${pendientes} sin leer`;

  return (
    <Link href={`/admin/${tono}/notificaciones`} className={CLASE_ENLACE} aria-label={etiqueta} title="Notificaciones">
      <Bell className="h-5 w-5" aria-hidden="true" />
      {pendientes !== null && pendientes > 0 ? (
        <span
          aria-hidden="true"
          className="absolute -right-0.5 -top-0.5 flex min-w-5 items-center justify-center rounded-full bg-tinta px-1 text-[11px] font-bold leading-5 text-white"
        >
          {pendientes > TOPE_VISIBLE ? `${TOPE_VISIBLE}+` : pendientes}
        </span>
      ) : null}
    </Link>
  );
}
