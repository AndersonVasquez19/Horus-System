import { expect, type Page, type TestInfo } from '@playwright/test';

/** Laravel falso (`e2e/mock-laravel.mjs`). */
export const API = 'http://127.0.0.1:4010';

export const esMovil = (info: TestInfo) => info.project.name === 'movil';

/**
 * Abre una ruta del panel y espera a que el contenido principal esté pintado **e hidratado**.
 *
 * Con `next dev` el HTML llega antes que el JavaScript: un clic dado en ese intervalo se pierde sin error. React marca
 * con una clave interna (`__reactFiber…`) cada nodo que ya hidrató, así que se espera a que el contenido y todos los
 * botones la tengan.
 */
export async function abrir(page: Page, ruta: string) {
  await page.goto(ruta);
  await expect(page.locator('#contenido-panel')).toBeVisible();
  await page.waitForFunction(() => {
    const nodos = [document.querySelector('#contenido-panel'), ...Array.from(document.querySelectorAll('button'))].filter((n): n is Element => n !== null);
    return nodos.length > 0 && nodos.every((nodo) => Object.keys(nodo).some((clave) => clave.startsWith('__reactFiber')));
  });
}

export interface Peticion {
  metodo: string;
  ruta: string;
  consulta: Record<string, string>;
  cuerpo: Record<string, unknown> | null;
}

/** Lo que el Laravel falso ha recibido desde la última limpieza. */
export async function peticiones(page: Page): Promise<Peticion[]> {
  const respuesta = await page.request.get(`${API}/__peticiones`);
  return (await respuesta.json()) as Peticion[];
}

export async function limpiarPeticiones(page: Page) {
  await page.request.delete(`${API}/__peticiones`);
}

/** Guarda una captura en `e2e/capturas` (ignorada por Git) para la revisión visual. */
export async function capturar(page: Page, info: TestInfo, nombre: string) {
  await page.screenshot({ path: `e2e/capturas/${info.project.name}-${nombre}.png` });
}
