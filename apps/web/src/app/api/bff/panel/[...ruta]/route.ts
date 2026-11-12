import { NextResponse, type NextRequest } from 'next/server';
import { aplicarCookieSesion, borrarCookieSesion, errorJson, jsonSinCache, leerMultipart, leerMutacion, origenPermitido } from '@/lib/panel/bff';
import { COOKIE_SESION, descargarDeLaravel, llamarLaravel } from '@/lib/panel/laravel';
import { buscarOperacion, consultaPermitida, cuerpoPermitido, type Metodo } from '@/lib/panel/operaciones-panel';
import { ipDelCliente } from '@/lib/panel/red';

/**
 * Operaciones del panel desde el navegador: `/api/bff/panel/<ruta>` → `/api/panel/v1/<ruta>` de Laravel.
 *
 * Capas, en orden:
 *  1. La operación debe estar en la lista cerrada (`operaciones-panel.ts`); si no, 404.
 *  2. Mismo origen (todas) y JSON con tamaño acotado (mutaciones): `leerMutacion`; o, en las operaciones
 *     multipart (imagen de la ficha), cuerpo acotado con una sola imagen de tipo real válido: `leerMultipart`.
 *  3. Cookie de sesión de Laravel presente; sin ella, 401 sin llamar a Laravel.
 *  4. Laravel valida el token CSRF que envía el navegador (`X-CSRF-TOKEN`, de la sesión ya validada), la sesión,
 *     el permiso de entrada al panel, el permiso de la acción y las reglas de negocio.
 * La cookie renovada por Laravel se reescribe con los atributos fijos del BFF; un 401 la borra.
 */

type Contexto = { params: Promise<{ ruta: string[] }> };

async function atender(request: NextRequest, metodo: Metodo, { params }: Contexto): Promise<NextResponse> {
  const { ruta: segmentos } = await params;
  const ruta = segmentos.join('/');
  const operacion = buscarOperacion(metodo, ruta);
  if (operacion === null) return errorJson(404, { message: 'El recurso solicitado no existe.', code: 'no_encontrado' });

  let cuerpo: Record<string, unknown> = {};
  let formulario: FormData | undefined;
  if (metodo === 'GET') {
    if (!origenPermitido(request)) return errorJson(403, { message: 'Solicitud no permitida.', code: 'origen_no_permitido' });
  } else if (operacion.multipart) {
    const leido = await leerMultipart(request, operacion);
    if (leido instanceof NextResponse) return leido;
    formulario = leido;
  } else {
    const leido = await leerMutacion(request);
    if (leido instanceof NextResponse) return leido;
    cuerpo = leido;
  }

  const cookieSesion = request.cookies.get(COOKIE_SESION)?.value;
  if (!cookieSesion) return errorJson(401, { message: 'Inicia sesión para continuar.', code: 'no_autenticado' });

  const destino = `${ruta}${consultaPermitida(operacion, request.nextUrl.searchParams)}`;
  const opciones = {
    metodo,
    cuerpo: formulario ? undefined : cuerpoPermitido(operacion, cuerpo),
    formulario,
    cookieSesion,
    csrf: metodo === 'GET' ? null : request.headers.get('x-csrf-token')?.slice(0, 200) ?? null,
    ipCliente: ipDelCliente(request.headers),
  };

  if (operacion.archivo) {
    const archivo = await descargarDeLaravel(destino, operacion.archivo, opciones);
    if (!archivo.ok) return cerrarSiExpiro(aplicarCookieSesion(errorJson(archivo.estado, archivo.error), archivo.cookie), archivo.estado);

    const respuesta = new NextResponse(archivo.contenido, {
      status: 200,
      headers: {
        'Content-Type': operacion.enLinea ? archivo.tipo : `${archivo.tipo}; charset=UTF-8`,
        'Content-Disposition': operacion.enLinea ? 'inline' : `attachment; filename="${archivo.nombre}"`,
        'Cache-Control': 'no-store, private',
        'X-Content-Type-Options': 'nosniff',
      },
    });
    return aplicarCookieSesion(respuesta, archivo.cookie);
  }

  const resultado = await llamarLaravel<unknown>(destino, opciones);
  const respuesta = resultado.ok ? jsonSinCache(resultado.datos, resultado.estado) : errorJson(resultado.estado, resultado.error);
  if (!resultado.ok && resultado.estado === 429) respuesta.headers.set('Retry-After', '60');

  return cerrarSiExpiro(aplicarCookieSesion(respuesta, resultado.cookie), resultado.estado);
}

function cerrarSiExpiro(respuesta: NextResponse, estado: number): NextResponse {
  return estado === 401 ? borrarCookieSesion(respuesta) : respuesta;
}

export function GET(request: NextRequest, contexto: Contexto) {
  return atender(request, 'GET', contexto);
}

export function POST(request: NextRequest, contexto: Contexto) {
  return atender(request, 'POST', contexto);
}

export function PUT(request: NextRequest, contexto: Contexto) {
  return atender(request, 'PUT', contexto);
}

export function DELETE(request: NextRequest, contexto: Contexto) {
  return atender(request, 'DELETE', contexto);
}
