// Reparto de rutas del gateway. Es una función pura: decide a quién se envía cada petición y nada más.
//
// Mismo mapa que docs/guia-despliegue.md («Mapa de rutas: Next.js y Laravel»):
//   - Laravel (API y archivos públicos): /up, /api/auth/*, /storage/*, /assets/images/*
//   - Next.js (interfaz y su BFF):       todo lo demás, incluido /api/bff/*
//   - Nunca expuestas al navegador:      /api/panel/* y /api/public/* (solo las llama el BFF de Next, de servidor a servidor,
//                                        con el token de servicio), y Horus AI (solo la llama Laravel por loopback).

export const DESTINO = Object.freeze({ LARAVEL: 'laravel', NEXT: 'next', BLOQUEADO: 'bloqueado' });

const esRuta = (ruta, base) => ruta === base || ruta.startsWith(`${base}/`);

/** @param {string} urlCruda `req.url` tal como llega (con query). */
export function destinoDe(urlCruda) {
  // La ruta se corta a mano: `new URL('//x/y', base)` toma `x` por host y descartaría `/api/panel` de `//api/panel/...`.
  const crudo = String(urlCruda);
  const corte = crudo.search(/[?#]/);
  const ruta = corte === -1 ? crudo : crudo.slice(0, corte);
  if (!ruta.startsWith('/')) return DESTINO.BLOQUEADO; // formas absolutas (`http://…`) o vacías
  // Se decide sobre la ruta decodificada y normalizada, para que `%2e%2e` o `//` no salten el reparto.
  let decodificada;
  try {
    decodificada = decodeURIComponent(ruta);
  } catch {
    return DESTINO.BLOQUEADO;
  }
  if (decodificada.includes('\0') || decodificada.split('/').includes('..')) return DESTINO.BLOQUEADO;
  const limpia = decodificada.replace(/\/{2,}/g, '/');

  if (esRuta(limpia, '/api/panel') || esRuta(limpia, '/api/public')) return DESTINO.BLOQUEADO;
  if (limpia === '/up' || esRuta(limpia, '/api/auth') || esRuta(limpia, '/storage') || esRuta(limpia, '/assets/images')) return DESTINO.LARAVEL;
  return DESTINO.NEXT;
}
