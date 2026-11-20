import { expect, test } from '@playwright/test';
import { abrir, esMovil } from './ayudas';

/**
 * Impresión. El navegador sin interfaz no abre el diálogo de impresión, así que se comprueba con el medio `print`
 * emulado (las mismas reglas CSS que usa el papel) y, para el botón «Imprimir», sustituyendo `window.print`.
 */
test.describe('Impresión de una página del panel', () => {
  test('oculta la interfaz y deja el contenido', async ({ page }) => {
    await abrir(page, '/admin/academico/estudiantes');
    await page.emulateMedia({ media: 'print' });

    await expect(page.getByRole('heading', { name: 'Estudiantes', level: 1 })).toBeVisible();
    await expect(page.getByRole('table')).toBeVisible();

    // Riel de navegación, filtros, acciones de cabecera, paginación y botones no salen en el papel.
    await expect(page.getByRole('navigation', { name: /^Navegación de/ })).toBeHidden();
    await expect(page.getByLabel('Buscar', { exact: true })).toBeHidden();
    await expect(page.getByRole('button', { name: 'Nuevo estudiante' })).toBeHidden();
    await expect(page.getByRole('navigation', { name: 'Paginación' })).toBeHidden();
    await expect(page.getByRole('button', { name: 'Acciones para Luis Torres Díaz' })).toBeHidden();
  });

  test('la tabla se imprime completa, sin recorte ni ancho mínimo', async ({ page }, info) => {
    test.skip(esMovil(info), 'El ancho de papel no depende de la ventana: basta con comprobarlo una vez.');
    await abrir(page, '/admin/academico/estudiantes');
    await page.emulateMedia({ media: 'print' });
    const medida = await page.locator('table').first().evaluate((t) => ({ min: getComputedStyle(t).minWidth, desborda: t.scrollWidth > t.clientWidth + 1 }));
    expect(medida.min).toBe('0px');
    expect(medida.desborda).toBe(false);
  });
});

interface VentanaConImpresiones {
  __impresiones: boolean[];
}

test.describe('Vista previa del reporte', () => {
  test.beforeEach(async ({ page }) => {
    // El navegador sin interfaz no abre el diálogo de impresión: se registra la llamada y qué marca llevaba el <body>.
    await page.addInitScript(() => {
      const ventana = window as unknown as VentanaConImpresiones;
      ventana.__impresiones = [];
      window.print = () => {
        ventana.__impresiones.push(document.body.classList.contains('imprimiendo-zona'));
      };
    });
    await abrir(page, '/admin/academico/reportes');
    await page.getByRole('button', { name: 'Vista previa' }).click();
    await expect(page.getByRole('dialog', { name: /Vista previa/ })).toBeVisible();
  });

  test('«Imprimir» marca el <body> solo durante la impresión y lo limpia después', async ({ page }) => {
    await page.getByRole('button', { name: 'Imprimir' }).click();

    expect(await page.evaluate(() => (window as unknown as VentanaConImpresiones).__impresiones)).toEqual([true]);
    // El navegador avisa al terminar de imprimir: la marca desaparece y la página vuelve a la normalidad.
    await page.evaluate(() => window.dispatchEvent(new Event('afterprint')));
    await expect(page.locator('body')).not.toHaveClass(/imprimiendo-zona/);
  });

  test('al imprimir solo queda la hoja del reporte, sin riel ni filtros', async ({ page }) => {
    await page.evaluate(() => document.body.classList.add('imprimiendo-zona'));
    await page.emulateMedia({ media: 'print' });

    const hoja = page.locator('.zona-impresion');
    await expect(hoja).toBeVisible();
    await expect(hoja.getByRole('heading', { name: 'Reporte de Matrículas' })).toBeVisible();
    await expect(hoja.getByText(/Generado el/)).toBeVisible();
    await expect(hoja.getByRole('table')).toBeVisible();
    await expect(page.getByRole('navigation', { name: /^Navegación de/ })).toBeHidden();
    await expect(page.locator('#contenido-panel')).toBeHidden();
  });

  test('en pantalla la hoja de impresión no se ve', async ({ page }) => {
    await expect(page.locator('.zona-impresion')).toBeHidden();
  });
});
