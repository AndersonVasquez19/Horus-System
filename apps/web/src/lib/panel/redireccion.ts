/**
 * Destinos permitidos tras iniciar sesión. Lista blanca: solo rutas relativas del panel, sin esquema, host,
 * barras dobles, barras invertidas ni caracteres de control. Cualquier otro valor vuelve a `/admin`.
 * Se usa en el servidor (proxy, guardias) y en el cliente (formulario de acceso).
 */
const PREFIJOS = ['/admin/academico'] as const;

export const DESTINO_PREDETERMINADO = '/admin';

export function destinoSeguro(valor: string | null | undefined): string {
  if (typeof valor !== 'string' || valor.length > 512) return DESTINO_PREDETERMINADO;
  if (!valor.startsWith('/') || valor.startsWith('//') || /[\\\u0000-\u001f\u007f]/.test(valor)) {
    return DESTINO_PREDETERMINADO;
  }

  const ruta = valor.split(/[?#]/, 1)[0];
  if (ruta.split('/').some((segmento) => segmento === '..' || segmento === '.')) return DESTINO_PREDETERMINADO;
  if (ruta === DESTINO_PREDETERMINADO) return valor;

  return PREFIJOS.some((prefijo) => ruta === prefijo || ruta.startsWith(`${prefijo}/`)) ? valor : DESTINO_PREDETERMINADO;
}

/** URL de acceso que vuelve al destino indicado después de iniciar sesión. */
export function urlLogin(destino: string): string {
  const seguro = destinoSeguro(destino);
  return seguro === DESTINO_PREDETERMINADO ? '/login' : `/login?next=${encodeURIComponent(seguro)}`;
}
