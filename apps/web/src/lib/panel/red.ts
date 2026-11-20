import 'server-only';

import { isIP } from 'node:net';

/**
 * IP del visitante para los limitadores de Laravel.
 *
 * Requisito de despliegue: el proxy de borde debe SOBRESCRIBIR `X-Forwarded-For` / `X-Real-IP` (no añadir a lo
 * que envía el cliente). Si faltan o no son una IP válida, no se envía nada y Laravel limita por la IP del BFF.
 * El límite por correo de Laravel se aplica siempre, con o sin IP.
 */
export function ipDelCliente(cabeceras: Headers): string | null {
  const candidata = cabeceras.get('x-real-ip')?.trim() || cabeceras.get('x-forwarded-for')?.split(',')[0]?.trim() || '';
  return isIP(candidata) ? candidata : null;
}
