import type { NextRequest } from 'next/server';
import { campos, leerMutacion } from '@/lib/panel/bff';
import { operacionConCsrf } from '@/lib/panel/operaciones-auth';

/**
 * Registro público. Laravel valida y crea la cuenta SIN roles ni acceso al panel; no devuelve token ni datos.
 * Los campos viajan como texto (`terms` = '1' cuando se acepta el aviso de privacidad).
 */
export async function POST(request: NextRequest) {
  const cuerpo = await leerMutacion(request);
  if (cuerpo instanceof Response) return cuerpo;

  return operacionConCsrf(request, 'auth/registro', campos(cuerpo, ['name', 'email', 'password', 'password_confirmation', 'terms']));
}
