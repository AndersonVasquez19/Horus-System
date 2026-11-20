'use client';

import { useEffect } from 'react';

/**
 * Último recurso: falla el propio layout raíz. Reemplaza al layout, así que define `<html>` y `<body>` y usa estilos en
 * línea (los estilos globales no se cargan aquí). No muestra detalles técnicos.
 */
export default function GlobalError({ error, retry }: { error: unknown; retry: () => void }) {
  useEffect(() => {
    console.error(error);
  }, [error]);

  return (
    <html lang="es">
      <body style={{ margin: 0, minHeight: '100vh', display: 'flex', alignItems: 'center', justifyContent: 'center', padding: 16, background: 'linear-gradient(135deg,#071727,#0c2942 58%,#102f49)', color: '#0A2540', fontFamily: 'system-ui, -apple-system, Segoe UI, sans-serif' }}>
        <title>Algo salió mal | Horus Group</title>
        <main style={{ width: '100%', maxWidth: 480, background: '#fff', borderRadius: 24, border: '1px solid rgba(201,169,97,0.3)', padding: 32 }}>
          <p aria-hidden="true" style={{ margin: 0, fontSize: 56, fontWeight: 700, lineHeight: 1, color: '#856424' }}>500</p>
          <h1 style={{ margin: '20px 0 8px', fontSize: 24 }}>Algo salió mal</h1>
          <p style={{ margin: 0, fontSize: 14, lineHeight: 1.6, color: '#475569' }}>La página no pudo cargarse. Inténtalo de nuevo en unos minutos.</p>
          <button type="button" onClick={() => retry()} style={{ marginTop: 24, border: 0, borderRadius: 12, background: '#0A2540', color: '#fff', padding: '10px 20px', fontSize: 14, fontWeight: 600, cursor: 'pointer' }}>
            Intentar de nuevo
          </button>
        </main>
      </body>
    </html>
  );
}
