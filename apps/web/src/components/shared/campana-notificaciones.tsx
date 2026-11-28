import 'server-only';

import { cache } from 'react';
import Link from 'next/link';
import { cookies, headers } from 'next/headers';
import { Bell } from 'lucide-react';
import { COOKIE_NAVEGADOR, llamarLaravel } from '@/lib/panel/laravel';
import { ipDelCliente } from '@/lib/panel/red';
import type { Notificacion } from '@/features/notificaciones/tipos';
import { DesplegableCampana } from './desplegable-campana';
import type { InsigniasNavegacion, TonoPanel } from './panel-tipos';
import { RECLAMACIONES_EN_PANEL } from '@/lib/funciones';

const CLASE_ENLACE =
  'relative inline-flex items-center rounded-md p-2 text-panel-text outline-offset-2 hover:bg-panel-bg focus-visible:outline-2 focus-visible:outline-academico-700';

interface ConteosCampana {
  notificaciones_sin_leer?: number;
  aprobaciones_pendientes?: number;
  reclamaciones_por_vencer?: number;
  mensajes_nuevos?: number;
}

interface ResumenCampana {
  recientes: Notificacion[];
  pendientes: number | null;
  insignias: InsigniasNavegacion;
}

const VACIO: ResumenCampana = { recientes: [], pendientes: null, insignias: {} };

const leerRecientes = cache(async (cookieSesion: string, ipCliente: string | null): Promise<ResumenCampana> => {
  const respuesta = await llamarLaravel<{ data?: Notificacion[]; meta?: { pendientes?: unknown; conteos?: ConteosCampana } }>(
    'compartido/notificaciones/recientes',
    { cookieSesion, ipCliente },
  );
  if (!respuesta.ok) return VACIO;

  const total = respuesta.datos.meta?.pendientes;
  const conteos = respuesta.datos.meta?.conteos ?? {};
  const pendientes = typeof total === 'number' && Number.isInteger(total) && total >= 0 ? total : null;

  const insignias: InsigniasNavegacion = {};
  if (typeof conteos.aprobaciones_pendientes === 'number' && conteos.aprobaciones_pendientes > 0) {
    insignias.aprobaciones = conteos.aprobaciones_pendientes;
  }
  const urgenciasReclamaciones = (RECLAMACIONES_EN_PANEL ? (conteos.reclamaciones_por_vencer ?? 0) : 0) + (conteos.mensajes_nuevos ?? 0);
  if (urgenciasReclamaciones > 0) {
    insignias.reclamaciones = urgenciasReclamaciones;
  }
  if (pendientes !== null && pendientes > 0) {
    insignias.notificaciones = pendientes;
  }

  return {
    recientes: Array.isArray(respuesta.datos.data) ? respuesta.datos.data : [],
    pendientes,
    insignias,
  };
});

/** Consulta en caché por petición: alimenta tanto la campana como las insignias de la barra lateral sin duplicar llamadas. */
export async function obtenerInsigniasNavegacion(): Promise<InsigniasNavegacion> {
  const cookieSesion = (await cookies()).get(COOKIE_NAVEGADOR)?.value;
  if (!cookieSesion) return {};
  return (await leerRecientes(cookieSesion, ipDelCliente(await headers()))).insignias;
}

/**
 * Campana del panel: desplegable con las últimas no leídas y enlace a la bandeja completa.
 * Si Laravel no responde, queda como enlace simple sin conteo para no afectar al resto del panel.
 */
export async function CampanaNotificaciones({ tono }: { tono: TonoPanel }) {
  const cookieSesion = (await cookies()).get(COOKIE_NAVEGADOR)?.value;
  if (!cookieSesion) return <CampanaSinConteo tono={tono} />;

  const resumen = await leerRecientes(cookieSesion, ipDelCliente(await headers()));
  return <DesplegableCampana tono={tono} iniciales={resumen.recientes} pendientesIniciales={resumen.pendientes} />;
}

/** Sin conteo (mientras carga o si falla): enlace simple a la bandeja, sin insignia. */
export function CampanaSinConteo({ tono }: { tono: TonoPanel }) {
  return (
    <Link href={`/admin/${tono}/notificaciones`} className={CLASE_ENLACE} aria-label="Notificaciones" title="Notificaciones">
      <Bell className="h-5 w-5" aria-hidden="true" />
    </Link>
  );
}
