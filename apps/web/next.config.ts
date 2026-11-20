import type { NextConfig } from "next";
import path from "path";

/**
 * Cabeceras de seguridad de las rutas del panel (acceso, /admin y el BFF). Se limitan a ellas para no alterar el
 * sitio público. La CSP fija solo directivas que NO dependen de scripts: `frame-ancestors 'none'` (clickjacking),
 * `object-src 'none'`, `base-uri 'self'` (sin <base> inyectado) y `form-action 'self'` (los formularios solo envían
 * al propio origen). NO incluye `script-src`: una política de scripts con nonces exige renderizado dinámico, y
 * /login, /recuperar-contrasena y /restablecer-contrasena son estáticas y /admin/* usa prerenderizado parcial
 * (`cacheComponents`), por lo que su HTML prerenderizado no puede llevar el nonce de cada petición. Ver
 * docs/fase-5-1-multimedia-y-produccion.md. HSTS no se fija aquí: lo emite el proxy que termina TLS.
 */
const CABECERAS_PANEL = [
  { key: "Content-Security-Policy", value: "frame-ancestors 'none'; object-src 'none'; base-uri 'self'; form-action 'self'" },
  { key: "Permissions-Policy", value: "camera=(), microphone=(), geolocation=(), payment=()" },
  { key: "X-Frame-Options", value: "DENY" },
  { key: "X-Content-Type-Options", value: "nosniff" },
  { key: "Referrer-Policy", value: "strict-origin-when-cross-origin" },
];

const nextConfig: NextConfig = {
  /* config options here */
  outputFileTracingRoot: path.resolve(__dirname),
  // Carpeta de compilación. Por omisión `.next`; las pruebas e2e fijan otra para convivir con un `next dev` abierto.
  distDir: process.env.HORUS_NEXT_DIST_DIR || ".next",
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
  /**
   * Direcciones que antes servía Laravel (Blade) y que siguen guardadas en notificaciones, aprobaciones, correos y en las
   * respuestas del chat. Son temporales (307): el destino puede cambiar sin que un navegador recuerde el anterior.
   */
  async redirects() {
    const modulosDelPanel = ["matriculas", "estudiantes", "cursos", "reportes", "buscar", "respaldo", "asistente"];
    return [
      ...modulosDelPanel.map((modulo) => ({
        source: `/admin/${modulo}/:path*`,
        destination: `/admin/academico/${modulo}/:path*`,
        permanent: false,
      })),
      { source: "/register", destination: "/registro", permanent: false },
      { source: "/forgot-password", destination: "/recuperar-contrasena", permanent: false },
      { source: "/libro-reclamaciones", destination: "/legales/libro-reclamaciones", permanent: false },
      { source: "/solicitar-cotizacion", destination: "/tecnologias/solicitar-cotizacion", permanent: false },
      { source: "/politicas-devolucion", destination: "/legales/politicas-devolucion", permanent: false },
      { source: "/politicas-privacidad", destination: "/legales/politicas-privacidad", permanent: false },
      { source: "/politicas-cookies", destination: "/legales/politicas-cookies", permanent: false },
      { source: "/preguntas-frecuentes", destination: "/legales/preguntas-frecuentes", permanent: false },
    ];
  },
  async headers() {
    return ["/admin/:path*", "/login", "/registro", "/recuperar-contrasena", "/restablecer-contrasena", "/api/bff/:path*"].map((source) => ({
      source,
      headers: CABECERAS_PANEL,
    }));
  },
};

export default nextConfig;
