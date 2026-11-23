import { cookies, headers } from 'next/headers';
import { redirect } from 'next/navigation';
import { COOKIE_NAVEGADOR, llamarLaravel } from '@/lib/panel/laravel';
import { ipDelCliente } from '@/lib/panel/red';
import type { SesionPanel } from '@/lib/panel/tipos';

/**
 * Si el visitante ya tiene una sesión vigente en Laravel, redirige al panel sin bloquear el render
 * inicial de los formularios de acceso (se monta dentro de `<Suspense fallback={null}>`).
 */
export async function ComprobarSesionActiva() {
  const cookieSesion = (await cookies()).get(COOKIE_NAVEGADOR)?.value;
  if (!cookieSesion) return null;

  const respuesta = await llamarLaravel<{ data: SesionPanel }>('auth/sesion', {
    cookieSesion,
    ipCliente: ipDelCliente(await headers()),
  });

  if (respuesta.ok && respuesta.datos.data.paneles.academico) {
    redirect('/admin/academico');
  }

  return null;
}
