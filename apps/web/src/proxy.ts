import { NextResponse, type NextRequest } from 'next/server';
import { COOKIE_NAVEGADOR } from '@/lib/panel/laravel';
import { urlLogin } from '@/lib/panel/redireccion';

/**
 * Comprobación optimista de /admin: sin cookie de sesión, redirige al acceso antes de renderizar.
 *
 * NO es autorización. Una cookie presente puede estar caducada o no tener permisos: lo decide Laravel en cada
 * lectura (las guardias de los layouts del panel y cada endpoint de la API). Aquí no se llama a Laravel.
 */
export function proxy(request: NextRequest) {
  if (request.cookies.has(COOKIE_NAVEGADOR)) return NextResponse.next();

  const destino = `${request.nextUrl.pathname}${request.nextUrl.search}`;
  return NextResponse.redirect(new URL(urlLogin(destino), request.url));
}

export const config = {
  matcher: ['/admin', '/admin/:path*'],
};
