import type { NextRequest } from 'next/server';

/** ¿La petición del navegador viene de este mismo sitio? Lo exigen las rutas del chat que reciben datos. */
export function mismoOrigen(request: NextRequest): boolean {
  const origen = request.headers.get('origin');
  const host = request.headers.get('x-forwarded-host') ?? request.headers.get('host');
  if (!origen || !host) return false;
  try {
    return new URL(origen).host === host;
  } catch {
    return false;
  }
}
