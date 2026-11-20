'use client';

import { useRouter } from 'next/navigation';
import { useState, type FormEvent } from 'react';
import { useOperacionPanel } from '@/components/shared/use-operacion-panel';
import { Alerta } from '@/components/shared/ui/alerta';
import { useAvisos } from '@/components/shared/ui/avisos';
import { Boton, type TonoUi } from '@/components/shared/ui/boton';
import { CampoClave } from '@/components/shared/ui/campo';
import { Insignia } from '@/components/shared/ui/insignia';
import type { SesionActiva } from '@/features/notificaciones/tipos';
import { errorDeCampo } from '@/lib/panel/cliente';
import type { ErrorPanel } from '@/lib/panel/tipos';

/** Resume un User-Agent crudo a «Navegador · Sistema»; el texto completo queda en el atributo `title`. */
function describirNavegador(ua: string | null): string {
  if (!ua || ua.trim() === '') return 'Navegador desconocido';

  let navegador = 'Navegador web';
  if (/Edg\//i.test(ua)) navegador = 'Microsoft Edge';
  else if (/OPR\/|Opera/i.test(ua)) navegador = 'Opera';
  else if (/Chrome\//i.test(ua) && !/Chromium/i.test(ua)) navegador = 'Google Chrome';
  else if (/Firefox\//i.test(ua)) navegador = 'Mozilla Firefox';
  else if (/Safari\//i.test(ua) && !/Chrome|Chromium|Edg/i.test(ua)) navegador = 'Safari';

  let sistema = '';
  if (/Windows NT/i.test(ua)) sistema = 'Windows';
  else if (/Android/i.test(ua)) sistema = 'Android';
  else if (/iPhone|iPad|iPod/i.test(ua)) sistema = 'iOS';
  else if (/Mac OS X|Macintosh/i.test(ua)) sistema = 'macOS';
  else if (/Linux/i.test(ua)) sistema = 'Linux';

  return sistema !== '' ? `${navegador} · ${sistema}` : navegador;
}

/**
 * Sesiones activas de la cuenta. Cerrar una o todas las demás exige la contraseña actual: así una sesión desatendida
 * no basta para expulsar al titular de sus otros dispositivos. Laravel identifica cada sesión por una huella, nunca
 * por su identificador real.
 */
export function SesionesActivas({ sesiones, disponible, tono }: { sesiones: SesionActiva[]; disponible: boolean; tono: TonoUi }) {
  const router = useRouter();
  const { ejecutar } = useOperacionPanel();
  const { mostrar } = useAvisos();
  const [clave, setClave] = useState('');
  const [objetivo, setObjetivo] = useState<string | 'otras' | null>(null);
  const [error, setError] = useState<ErrorPanel | null>(null);
  const [cargando, setCargando] = useState(false);

  if (!disponible) {
    return (
      <section aria-labelledby="titulo-sesiones" className="rounded-xl border border-panel-border bg-panel-surface p-5 shadow-sm">
        <h2 id="titulo-sesiones" className="font-heading text-base font-semibold text-panel-text">Sesiones activas</h2>
        <p className="mt-2 text-sm text-panel-muted">El servidor no guarda las sesiones en la base de datos, así que no se pueden listar.</p>
      </section>
    );
  }

  async function confirmar(evento: FormEvent<HTMLFormElement>) {
    evento.preventDefault();
    if (objetivo === null) return;
    setCargando(true);
    setError(null);
    const destino = objetivo === 'otras' ? 'compartido/perfil/sesiones/cerrar-otras' : `compartido/perfil/sesiones/${objetivo}/cerrar`;
    const resultado = await ejecutar<{ message?: string }>(destino, 'POST', { current_password: clave });
    setCargando(false);
    if (!resultado.ok) {
      setError(resultado.error);
      return;
    }
    setClave('');
    setObjetivo(null);
    mostrar('exito', resultado.datos.message ?? 'Sesión cerrada.');
    router.refresh();
  }

  const otras = sesiones.filter((sesion) => !sesion.actual).length;

  return (
    <section aria-labelledby="titulo-sesiones" className="space-y-4 rounded-xl border border-panel-border bg-panel-surface p-5 shadow-sm">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h2 id="titulo-sesiones" className="font-heading text-base font-semibold text-panel-text">Sesiones activas</h2>
        <Boton tono={tono} variante="secundario" disabled={otras === 0} onClick={() => { setError(null); setObjetivo('otras'); }}>
          Cerrar las demás sesiones
        </Boton>
      </div>

      {sesiones.length === 0 ? <p className="text-sm text-panel-muted">No hay sesiones activas registradas.</p> : null}
      <ul className="divide-y divide-panel-border">
        {sesiones.map((sesion) => (
          <li key={sesion.identificador} className="flex flex-wrap items-center justify-between gap-3 py-3 text-sm">
            <div className="min-w-0">
              <p className="truncate font-medium text-panel-text" title={sesion.navegador ?? undefined}>
                {describirNavegador(sesion.navegador)}
              </p>
              <p className="text-xs text-panel-muted">
                {sesion.ip ?? 'IP desconocida'} · última actividad {new Date(sesion.ultima_actividad).toLocaleString('es-PE', { timeZone: 'America/Lima' })}
              </p>
            </div>
            {sesion.actual ? (
              <Insignia color="verde">Esta sesión</Insignia>
            ) : (
              <Boton tono={tono} variante="fantasma" className="px-2 py-1 text-xs" onClick={() => { setError(null); setObjetivo(sesion.identificador); }}>
                Cerrar
              </Boton>
            )}
          </li>
        ))}
      </ul>

      {objetivo !== null ? (
        <form onSubmit={confirmar} noValidate className="space-y-3 rounded-lg border border-panel-border bg-panel-bg p-4">
          <p className="text-sm text-panel-text">
            {objetivo === 'otras' ? `Se cerrarán ${otras} sesión(es) y se cerrará el acceso desde otros dispositivos.` : 'Se cerrará esa sesión.'} Confirma con tu contraseña actual.
          </p>
          {error && error.code !== 'validacion' ? <Alerta tipo="error">{error.message}</Alerta> : null}
          <CampoClave tono={tono} etiqueta="Contraseña actual" value={clave} onChange={(e) => setClave(e.target.value)} error={errorDeCampo(error, 'current_password')} autoComplete="current-password" required />
          <div className="flex justify-end gap-2">
            <Boton tono={tono} variante="secundario" onClick={() => { setObjetivo(null); setClave(''); setError(null); }} disabled={cargando}>Cancelar</Boton>
            <Boton tono={tono} type="submit" variante="peligro" cargando={cargando}>Cerrar sesión</Boton>
          </div>
        </form>
      ) : null}
    </section>
  );
}
