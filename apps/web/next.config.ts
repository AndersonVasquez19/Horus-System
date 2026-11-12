import type { NextConfig } from "next";
import path from "path";

/**
 * Cabeceras de seguridad de las rutas del panel (acceso, /admin y el BFF). Se limitan a ellas para no alterar el
 * sitio público. `frame-ancestors 'none'` impide enmarcar el panel (clickjacking) sin introducir una CSP completa de
 * scripts: esa requiere nonces y se documenta como mejora en docs/fase-5-1-multimedia-y-produccion.md.
 * HSTS no se fija aquí: lo emite el proxy que termina TLS.
 */
const CABECERAS_PANEL = [
  { key: "Content-Security-Policy", value: "frame-ancestors 'none'" },
  { key: "X-Frame-Options", value: "DENY" },
  { key: "X-Content-Type-Options", value: "nosniff" },
  { key: "Referrer-Policy", value: "strict-origin-when-cross-origin" },
];

const nextConfig: NextConfig = {
  /* config options here */
  outputFileTracingRoot: path.resolve(__dirname),
  poweredByHeader: false,
  cacheComponents: true,
  partialPrefetching: true,
  turbopack: {
    root: path.resolve(__dirname),
    rules: {
      "*.css": {
        loaders: ["@tailwindcss/turbopack"],
        as: "*.css",
      },
    },
  },
  async headers() {
    return ["/admin/:path*", "/login", "/recuperar-contrasena", "/restablecer-contrasena", "/api/bff/:path*"].map((source) => ({
      source,
      headers: CABECERAS_PANEL,
    }));
  },
};

export default nextConfig;
