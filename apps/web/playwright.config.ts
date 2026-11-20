import { defineConfig, devices } from '@playwright/test';

/**
 * Pruebas de interfaz en navegador real (Chromium) del panel `/admin`.
 *
 * El panel depende de Laravel para todo (sesión, listados, formularios). En vez de requerir el backend, se levanta
 * `e2e/mock-laravel.mjs`, un Laravel falso mínimo, y `next dev` apunta a él. Así se prueba la INTERFAZ (diálogos, menús,
 * formularios, impresión, accesibilidad), no las reglas de negocio ni la integración real.
 *
 * Se usa `next dev` con Turbopack (el predeterminado): Tailwind está conectado solo a través de sus reglas en
 * `next.config.ts` y no hay `postcss.config`, así que con `--webpack` no se generaría ninguna clase utilitaria.
 * Compila en `.next-e2e` (variable HORUS_NEXT_DIST_DIR), de modo que puede convivir con un `next dev` abierto en esta carpeta.
 */
/**
 * El navegador entra por `localhost`, no por `127.0.0.1`: Next 16 bloquea los recursos de desarrollo (`/_next/hmr`) de
 * cualquier otro host, y sin ellos la página no hidrata y ningún componente interactivo funciona.
 */
const WEB = 'http://localhost:3100';
const API = 'http://127.0.0.1:4010';
/** El BFF exige un token de servicio de al menos 32 caracteres. */
const TOKEN = 'token-de-pruebas-e2e-0123456789-abcdef';

const entornoServidor = {
  HORUS_API_URL: API,
  HORUS_WEB_SERVICE_TOKEN: TOKEN,
  HORUS_WEB_ORIGIN: WEB,
  HORUS_WEB_URL: WEB,
  // Carpeta de compilación propia: así no choca con un `next dev` ya abierto en esta carpeta.
  HORUS_NEXT_DIST_DIR: '.next-e2e',
};

export default defineConfig({
  testDir: './e2e',
  testMatch: '**/*.spec.ts',
  outputDir: './test-results',
  timeout: 60_000,
  expect: { timeout: 10_000 },
  // `next dev` compila cada ruta la primera vez: un solo trabajador evita saturarlo.
  workers: 1,
  retries: 0,
  reporter: [['list']],
  use: {
    baseURL: WEB,
    locale: 'es-PE',
    timezoneId: 'America/Lima',
    trace: 'retain-on-failure',
    // Sesión ya iniciada: el proxy solo comprueba que exista la cookie y Laravel (falso) la valida.
    storageState: {
      cookies: [{ name: 'horus-sesion', value: 'sesion-de-prueba', domain: 'localhost', path: '/', httpOnly: true, secure: false, sameSite: 'Lax', expires: -1 }],
      origins: [],
    },
  },
  projects: [
    { name: 'escritorio', use: { ...devices['Desktop Chrome'], viewport: { width: 1280, height: 800 } } },
    { name: 'movil', use: { ...devices['Desktop Chrome'], viewport: { width: 360, height: 740 }, isMobile: true, hasTouch: true } },
  ],
  webServer: [
    {
      command: 'node e2e/mock-laravel.mjs',
      url: `${API}/__peticiones`,
      reuseExistingServer: true,
      timeout: 15_000,
    },
    {
      command: 'npx next dev -p 3100',
      url: `${WEB}/login`,
      reuseExistingServer: true,
      timeout: 240_000,
      env: entornoServidor,
    },
  ],
});
